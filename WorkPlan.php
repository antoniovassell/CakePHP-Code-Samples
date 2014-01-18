<?php
/**
 * WorkPlan
 *
 * @author 		Antonio Vassell
 * @copyright 	2013 Antonio Vassell
 * @license 	MIT
*/
class WorkPlan extends PmapAppModel{
	public $name = 'WorkPlan';
	public $primaryKey = 'employee_id';
	public $actsAs = array('Containable');

	public $belongsTo = array(
		'CurrentJobDetail'=>array(
			'className'=>'Esim.CurrentJobDetail',
			'foreignKey'=>'employee_id'
		),
		'Reviewer'=>array(
			'className'=>'Esim.CurrentJobDetail',
			'foreignKey'=>'reviewer_id'
		),
		'Supervisor'=>array(
			'className'=>'Esim.CurrentJobDetail',
			'foreignKey'=>'supervisor_id'
		)
	);

	/**
	* List of subordinate with the details of their work plans
	*
	* @param  string $super_id  The supervisor whom you want to get the subordinates of.
	* @return array 			List of subordinate with the details of their work plans
	*/
	public function getSubWorkPlans($super_id){
		$this->CurrentJobDetail->bindModel(
			array(
				'hasOne'=>array(
					'WorkPlan'=>array(
						'className'=>'Pmap.WorkPlan',
						'foreignKey'=>'employee_id'
					)
				)
			)
		);

		$work_plans = $this->CurrentJobDetail->find(
			'all',
			array(
				'conditions'=>array(
					'CurrentJobDetail.supervisor_id'=>$super_id
				),
				'fields'=>array('CurrentJobDetail.*','WorkPlan.*','Employee.employee_id,Employee.first_name,Employee.last_name,Employee.title')
			)
		);

		return $work_plans;
	}

	/**
	* Creates a new work plan record if one isn't present while updating the status
	* of an employee's work plan
	*
	* @return boolean 	
	*/
	public function createWorkPlan($employee_id,$super_id){
		$reviewer = ClassRegistry::init('Esim.CurrentJobDetail')->findById($super_id);
		$reviewer_id = $reviewer['CurrentJobDetail']['supervisor_id'];
		$work_plan = array(
			'employee_id'=>$employee_id,
			'created'=>date('Y-m-d H:i:s'),
			'modified'=>date('Y-m-d H:i:s'),
			'created_by'=>$super_id,
			'modified_by'=>$super_id,
			'reviewer_id'=>$reviewer_id,
			'status'=>'Modified',
			'status_updated'=>date('Y-m-d H:i:s')
		);
		if($this->save($work_plan)){
			return true;
		}
		return false;
	}

	/**
	* Updates the status of an employees work plan
	* And handles the events that should be triggered after
	*
	* @param string $employee_id	The employee whos workplan is being updated
	* @param string $updater_id		The supervisor of the employee whos workplan is being updated or the person updating the workplan (Supervisor, Reviewer, HR Member)
	* @param string $status 		The status of what the work plan should be updated to
	* @param string $user_type 		The type of user that is updating the employee's work plan, (super, reviewer, hr)
	*
	* @return array 				This array contains "success" which is a boolean true or false, "message" which shows why success is false/failed
	*/
	public function updateWorkPlanStatus($employee_id,$updater_id,$status='Modified',$user_type='superisor',$comments=null){

		if(is_array($employee_id) and !empty($employee_id)){
			try{
				$this->updateAll(
					array(
						'WorkPlan.status'=>'"Modified"',
						'WorkPlan.team_member_id'=>'"'.$updater_id.'"',
						'WorkPlan.modified'=>'"'.date('Y-m-d H:i:s').'"',
						'WorkPlan.modified_by'=>'"'.$updater_id.'"',
						'WorkPlan.team_member_comments'=>'"'.$comments.'"'
					),
					array('WorkPlan.employee_id'=>$employee_id)
				);

				//FIXME: Alert All users that their work plan has been modified
				return true;
			}
			catch(Exception $e){
				$message = 'Caught exception: '.$e->getMessage();
				CakeLog::critical($message);
				return false;
			}
		}

		$work_plan = $this->findByEmployeeId($employee_id);
		if(!empty($work_plan)){
			if($work_plan['WorkPlan']['status']!=$status){
				$work_plan['WorkPlan']['employee_id'] = $employee_id;
				$work_plan['WorkPlan']['status']= $status;
				$work_plan['WorkPlan']['status_updated']= date('Y-m-d H:i:s');
				$work_plan['WorkPlan']['modified'] = date('Y-m-d H:i:s');	
				$work_plan['WorkPlan']['modified_by'] = $updater_id;
				
				if($user_type=='supervisor'){
					$work_plan['WorkPlan']['supervisor_id'] = $updater_id;
					$work_plan['WorkPlan']['supervisor_comments'] = $comments;
				}elseif($user_type=='reviewer'){
					$work_plan['WorkPlan']['reviewer_id'] = $updater_id;
					$work_plan['WorkPlan']['reviewer_comments'] = $comments;
				}elseif($user_type=='hr'){
					$work_plan['WorkPlan']['hr_member_id'] = $updater_id;
					$work_plan['WorkPlan']['hr_member_comments'] = $comments;
				}

				if(!$this->save($work_plan)){
					//After saving, Alert the relevant users 
					if(!$this->afterStatusUpdate($work_plan)){
						return array('success'=>false,'message'=>'Failed to update workplan status');
					}
				}
			}
		}else{
			if(!$this->createWorkPlan($employee_id,$updater_id)){
				return array('success'=>false,'message'=>'Failed to update workplan status');
			}
		}

		return array('success'=>true,'message'=>'');
	}

	/**
	* Triggers the correct events after a work plan is updated such as alerting (email) the relevant users. 
	* 
	* @param array $work_plan 	Data of the workplan that was updated
	* 
	* @return boolean 			Succes
	*/
	public function afterStatusUpdate($work_plan){
		if(!empty($work_plan) and is_array($work_plan) and isset($work_plan['WorkPlan']['status'])){
			$success = false;
			switch($work_plan['WorkPlan']['status']){
				case 'Finalized':
					$success = afterFinailizedUpdated($work_plan);
					break;

				case 'HR Approved':
					$success = afterHRApprovalUpdated($work_plan);
					break;

				case 'HR Not Approved':
					$success = afterHRDenialUpdated($work_plan);
					break;

				case 'Reviewer Approved':
					$success = afterReviewerApprovalUpdate($work_plan);
					break;

				case 'Reviewer Not Approved':
					$success = afterReviewerDenialUpdate($work_plan);
					break;

				default:
					return false;
			}
			return $success;
		}
		return false;
	}

	/**
	* Queries and returns the data for the employee, there supervisor and reviewer of the workplan being updated
	* 
	* @param string $employee_id 	
	* 
	* @return array 		
	*/
	protected function getEmployeeInfoForworkplanUpdateAlert($employee_id){
		$conditions = array(
			'joins'=>array(
				array(
					'table'=>'employees',
					'type'=>'inner',
					'alias'=>'Employee',
					'conditions'=>array('CurrentJobDetail.id = Employee.employee_id')
				),
				array(
					'table'=>'current_job_details',
					'type'=>'inner',
					'alias'=>'SupervisorJob',
					'conditions'=>array('CurrentJobDetail.supervisor_id = SupervisorJob.id')
				),
				array(
					'table'=>'current_job_details',
					'type'=>'inner',
					'alias'=>'ReviewerJob',
					'conditions'=>array('SupervisorJob.supervisor_id = ReviewerJob.id')
				),
				array(
					'table'=>'employees',
					'type'=>'inner',
					'alias'=>'SupervisorJobName',
					'conditions'=>array('SupervisorJob.supervisor_id = SupervisorJobName.employee_id')
				),
				array(
					'table'=>'employees',
					'type'=>'inner',
					'alias'=>'ReviewerJobName',
					'conditions'=>array('ReviewerJob.id = ReviewerJobName.employee_id')
				)
			),
			'conditions'=>array(
				'CurrentJobDetail.id'=>$employee_id
			),
			'fields'=>array(
				'Employee.title,Employee.employee_id,Employee.first_name,Employee.last_name',
				'SupervisorJob.employee_id,SupervisorJob.title,SupervisorJob.first_name,SupervisorJob.last_name',
				'ReviewerJobName.employee_id,ReviewerJobName.title,ReviewerJobName.first_name,ReviewerJobName.last_name'
			),
			'recursive'=>-1
		);
		try{
			$employee = $this->CurrentJobDetail->find('all',$conditions);
			return $employee;
		}
		catch(Exception $e){
			$message = 'Caught exception: '.$e->getMessage();
			CakeLog::critical($message);
			return false;
		}
	}

	protected function afterFinailizedUpdated($work_plan){
		$params['subject'] = 'Work Plan To Be Reviewed';
		$params['template'] = 'work_plan_finalized_update';

		if($this->workplanUpdateAlert($params)){
			return true;
		}
		return false;
	}

	protected function afterReviewerApprovalUpdate($work_plan){
		$params['subject'] = 'Work Plan To Be Reviewed';
		$params['template'] = 'work_plan_finalized_update';

		//FIXME: Set to variable
		if($this->workplanUpdateAlert($params)){
			return true;
		}
		return false;
	}

	protected function afterReviewerDenialUpdate($work_plan){
		$params['subject'] = 'Work Plan To Be Reviewed';
		$params['template'] = 'work_plan_finalized_update';

		//FIXME: Set to variable
		if($this->workplanUpdateAlert($params)){
			return true;
		}
		return false;
	}

	protected function afterHRApprovalUpdate($work_plan){
		$params['subject'] = 'Work Plan To Be Reviewed';
		$params['template'] = 'work_plan_finalized_update';

		//FIXME: Set to variable
		if($this->workplanUpdateAlert($params)){
			return true;
		}
		return false;
	}

	protected function afterHRDenialUpdate($work_plan){
		//Alert Supervisor
		//Copy Reviewer
		$params['subject'] = 'Work Plan To Be Reviewed';
		$params['template'] = 'work_plan_finalized_update';

		//FIXME: Set to variable
		if($this->workplanUpdateAlert($params)){
			return true;
		}
		return false;
	}

	protected function afterModifiedUpdate($work_plan){
		//Alert Employee
		//Copy Supervisor 
		$params['subject'] = 'Work Plan To Be Reviewed';
		$params['template'] = 'work_plan_finalized_update';

		if($this->workplanUpdateAlert($params)){
			return true;
		}
		return false;
	}	

	/**
	* This function does the actual emailing given the right parameters
	*
	* @param array $params 		Should contain email parameters such as subject, to and email template. 
	*							If viewVars not found, data will be pulled for employee info that is
	* @param array $work_plan 	Employee work plan info
	*
	* @return boolean			Success, if the alert was emailed successfully
	*/
	protected function workplanUpdateAlert($params,$work_plan){
		App::uses('CakeEmail','Network/Email');
		
		try{
			if(!isset($params['viewVars']) or empty($params['viewVars'])){
				$employee_info = $this->getEmployeeInfoForUpdateAlert($work_plan);
				if(isset($employee_info) and !empty($employee_info)){
					$params['viewVars'] = $employee_info;
				}else{
					return false;
				}
			}
			
			$Email = new CakeEmail('jnbs_config');
			$Email->to($params['to'])
		    ->subject($params['subject'])
		    ->template($params['template'])
		    ->emailFormat('html')
		    ->viewVars($params['viewVars'])
		    ->send();
		    return true;
		}
		catch(Exception $e){
			//Log or alert admin
			//$e->getMessage();
			return false;
		}
	}

	/**
	* Returns a List all workplans that are assigned to a specific reviewer to be reviewered after a 
	* supervisor signifies he has "Finalized" it. 
	*
	* @param string $reviewer_id	The id of the reviewer
	*
	* @return array 				List of workplans that needs to be reviewed by the specific reviewer
	*/
	public function getReviewerWorkPlansToReview($reviewer_id){
		$work_plans = $this->find(
			'all',
			array(
				'conditions'=>array(
					'WorkPlan.status'=>'Finalized',
					'WorkPlan.reviewer_id'=>$reviewer_id
				),
				'recursive'=>-1,
				'contain'=>array(
					'CurrentJobDetail'=>array(
						'id',
						'company_code',
						'department_id',
						'supervisor_id',
						'Employee.title,Employee.employee_id,Employee.first_name,Employee.last_name',
						'JobTitle'
					),
					'Reviewer'=>array(
						'id',
						'company_code',
						'department_id',
						'supervisor_id',
						'Employee.title,Employee.employee_id,Employee.first_name,Employee.last_name'
					),
					'Supervisor'=>array(
						'id',
						'company_code',
						'department_id',
						'supervisor_id',
						'Employee.title,Employee.employee_id,Employee.first_name,Employee.last_name'
					)
				)
			)
		);
		if(!empty($work_plans)){
			return $work_plans;
		}
		return array();
	}

	/**
	* Returns a list of employee workplans that require review by an HR Reviewer,
	* This is after a Reviewer has approved it
	*
	* @return void 			List of Work Plans to be reviewed by an HR Reviewer
	*/
	public function getHrReviewerWorkPlansToReview(){
		$work_plans = $this->find(
			'all',
			array(
				'conditions'=>array(
					'WorkPlan.status'=>'Reviewer Approved'
				),
				'recursive'=>-1,
				'contain'=>array(
					'CurrentJobDetail'=>array(
						'id',
						'company_code',
						'department_id',
						'supervisor_id',
						'Employee.title,Employee.employee_id,Employee.first_name,Employee.last_name',
						'JobTitle'
					),
					'Reviewer'=>array(
						'id',
						'company_code',
						'department_id',
						'supervisor_id',
						'Employee.title,Employee.employee_id,Employee.first_name,Employee.last_name'
					),
					'Supervisor'=>array(
						'id',
						'company_code',
						'department_id',
						'supervisor_id',
						'Employee.title,Employee.employee_id,Employee.first_name,Employee.last_name'
					)
				)
			)
		);
		if(!empty($work_plans)){
			return $work_plans;
		}
		return array();
	}
}