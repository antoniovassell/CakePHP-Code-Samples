<?php
/**
 * TargetsController
 *
 * @author 		Antonio Vassell
 * @copyright 	2013 Antonio Vassell
 * @license 	MIT
*/

class TargetsController extends PmapAppController{
	public $name = 'Targets';
	
	/**
	* Determines the appropriate menu element to be displayed depending on the the group that the 
	* user belongs to
	* This makes use of the component GroupChecker
	*
	* @return void
	*/
	public function beforeRender(){
		parent::beforeRender();

		if(!$this->request->isAjax()){
			if($this->GroupChecker->check($this->Auth->user('id'),'Executive')){
				$this->set('page_element','Pmap.menu_unit_plan');	
			}elseif($this->GroupChecker->check($this->Auth->user('id'),'Strat Planner')){
				$this->set('page_element','Pmap.menu_strategic_plan');	
			}elseif($this->GroupChecker->check($this->Auth->user('id'),'Perman Team')){
				$this->set('page_element','Pmap.menu_perman_team');	
			}elseif($this->GroupChecker->check($this->Auth->user('id'),'Employee')){
				$this->set('page_element','Pmap.menu_employee_work_plan');	
			}
		}
	}

	/**
	* View for adding a new target.
	*
	* @return void
	*/
	public function new_target(){
		$type = null;
		$parent_id = null;
		$parent = null;
		if(!empty($this->request->data)){
			$data = $this->request->data;
			/**
			* Determine the type of Target you are adding
			*/
			if($data['type']=='Objective'){
				$type = 'Objective';
			}elseif($data['type']=='Key Action'){
				$type = 'Key Action';
				$parent_id = $this->request->named['parent_id'];
			}elseif($data['type']=='Target'){
				$type = 'Target';
				$parent_id = $this->request->named['parent_id'];
				$this->set('employee_id',$this->Auth->user('id'));
			}elseif($data['type']=='Initiative'){
				$type = 'Initiative';
				$parent_id = $this->request->named['parent_id'];
			}
		}
		if($parent_id){
			$parent = $this->Target->findById($parent_id);
		}
		$company_code = ClassRegistry::init('Esim.CurrentJobDetail')->field('company_code',array('id'=>$this->Auth->user('id')));
		$strategic_plan = ClassRegistry::init('Pmap.StrategicPlan')->getCurrent($company_code);
		$strategic_plan_id = $strategic_plan['StrategicPlan']['id'];

		$directions = array('Increase','Decrease');		//Options for the direction field
		$this->set(compact('type','parent_id','parent','directions','strategic_plan_id'));
	}

	/**
	* View to handle posted data to add a new target
	*
	* @return void
	*/
	public function add_target(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$data = $this->request->data['Target'];
			$date = date('Y-m-d H:i:s');

			// Just making sure due date is not before start date
			if(!$this->validDateRanges($data['start_date'],$data['due_date'])){
				return json_encode(array('success'=>false,'message'=>'Due date cannot be before start date'));
			}
			
			$new_target = array(
				'id'=>$data['id'],
				'name'=>$data['name'],
				'level'=>$data['type'],
				'completion'=>$data['percentage_completion_ytd'],
				'strategic_plan_id'=>$data['strategic_plan_id'],
				'parent_id'=>$data['parent_id'],
				'created_by'=>$this->Auth->user('id'),
				'modified_by'=>$this->Auth->user('id'),
				'start_date'=>$data['start_date'],
				'due_date'=>$data['due_date'],
				'calculation_type'=>$data['calculation_type'],
				'expected_result'=>$data['expected_result'],
				'frequency_update'=>$data['frequency_update']
			);

			// Determine the exact type of target you are adding (objective, initiative, key action, target)
			$new_target = $this->checkTargetFormat($new_target,$data);
			
			if($data['type']!='Objective'){
				$new_target['weighting_of_parent_target'] = $data['weighting_of_parent_target'];
			}
			
			// Determine what is the process of the target so far
			$progress = $this->Target->calcProgressRawTarget($new_target);

			$new_target['completion'] = $progress['current_progress'];
			$new_target['progress_flag'] = $progress['progress_flag'];
			$new_target['ideal_progress'] = $progress['ideal_progress'];

			if($this->Target->save($new_target)){
				return json_encode(array('success'=>true,'message'=>'','target_id'=>$this->Target->id));
			}
			return json_encode(array('success'=>false,'message'=>'An error occured while saving'));
		}
	}

	/**
	* Validate the date range of target start date and end date,
	* Just making sure end date is not before start date
	*
	* @param string 	$start_date 	Start date of target
	* @param string 	$end_date		End or Due date of target
	*
	* @return boolean	true if range is a valid
	*/
	public function validDateRanges($start_date,$end_date){
		$start_date = strtotime($start_date);
		$end_date = strtotime($end_date);
		return ($end_date>$start_date);
	}

	/**
	* Formats the posted data of a new target into the appropriate type
	* so to be able to be saved with the correct information
	* 
	* @param array 	$target 	Initial formated target data
	* @param array  $data 		Posted target data
	*
	* @return array 	Formated target data to be saved
	*/
	protected function checkTargetFormat($target,$data){
		if($data['calculation_type']=='Percentage'){
			$target['target_percentage_completion']=$data['target_percentage_completion'];
			$target['percentage_completion_ytd']=$data['percentage_completion_ytd'];
			$target['direction'] = $data['percentage_base_direction'];

		}elseif($data['calculation_type']=='Direct Value'){
			$target['raw_value'] = $data['raw_value'];
			$target['value_ytd'] = $data['value_ytd'];
			$target['direction'] = $data['direct_direction'];

		}elseif($data['calculation_type']=='Currency'){
			$target['target_amount'] = $data['target_amount'];
			$target['ytd_currency_amount'] = $data['amount_ytd'];
			$target['direction'] = $data['currency_direction'];

		}elseif($data['calculation_type']=='Ratio'){
			$target['target_ratio_to'] = $data['target_ratio_to'];
			$target['target_ratio_from'] = $data['target_ratio_from'];
			$target['ytd_ratio_to'] = $data['ratio_YTD_to'];
			$target['ytd_ratio_from'] = $data['ratio_YTD_from'];
			$target['ratio_direction'] = $data['ratio_direction'];
		}else{
			$target['percentage_completion_ytd'] = $data['percentage_completion_ytd']; 	//What is this for?
		}

		return $target;
	}

	/**
	* Save data that was posted for a KPI
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function add_kpi(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$new_kpi = $this->request->data['Kpi'];
			$status = $this->validate_kpi($new_kpi);
			if($status['success']){
				$progress = $this->Target->calcProgressRawKpi($new_kpi);
				$new_kpi['completion'] = $progress['current_progress'];
				$new_kpi['progress_flag'] = $progress['progress_flag'];
				$new_kpi['ideal_progress'] = $progress['ideal_progress'];

				if($this->Target->TargetKpi->save($new_kpi)){
					$target = $this->Target->findById($new_kpi['target_id']);
					$this->Target->calcProgressBaseOnKpi($target['Target']);
					return json_encode(array('success'=>true,'message'=>'','kpi_id'=>$this->Target->TargetKpi->id));	
				}else{
					return json_encode(array('success'=>false,'message'=>'KPI Could not be saved'));	
				}
			}else{
				return json_encode(array('success'=>false,'message'=>'KPI Invalid'));
			}
		}
		return json_encode(array('success'=>false,'message'=>'An error occured while saving'));
	}

	/**
	* Validate information for a KPI
	*
	* @return boolean 	returns true if information is valid
	*/
	public function validate_kpi($kpi){
		$status = array('success'=>true);
		//FIXME: Please complete this
		return $status;
	}

	/**
	* Removes a KPI from a target
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function remove_kpi(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){		
			if($this->Target->TargetKpi->delete($this->request->data['kpi_id'])){
				return json_encode(array('success'=>true,'message'=>''));
			}
		}
		return json_encode(array('success'=>false,'message'=>'An error occured while saving'));
	}

	/**
	* Adds a deliverable to a target
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function add_deliverable(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$new_deliverable = $this->request->data['Deliverable'];
			
			if($this->Target->TargetDeliverable->save($new_deliverable)){
				return json_encode(array('success'=>true,'message'=>'','deliverable_id'=>$this->Target->TargetDeliverable->id));
			}
		}
		return json_encode(array('success'=>false,'message'=>'An error occured while saving'));
	}

	/**
	* Removes a deliverable from a target
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function remove_deliverable(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){		
			if($this->Target->TargetDeliverable->delete($this->request->data['deliverable_id'])){
				return json_encode(array('success'=>true,'message'=>''));
			}
		}
		return json_encode(array('success'=>false,'message'=>'An error occured while saving'));	
	}

	/**
	* Assigns a user to a target
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function add_assignment(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$new_assignment = array(
				'target_id'=>$this->request->data['target_id'],
				'employee_id'=>$this->request->data['employee_id'],
				'assigned_by'=>$this->Auth->user('id')
			);

			if($this->Target->TargetOwner->save($new_assignment)){
				return json_encode(array('success'=>true,'message'=>''));
			}
		}
		return json_encode(array('success'=>false,'message'=>'An error occured'));
	}

	/**
	* Removes an assignment/user from a target
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function remove_assignment(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$conditions = array(
				'TargetOwner.target_id'=>$this->request->data['target_id'],
				'TargetOwner.employee_id'=>$this->request->data['employee_id']
			);

			if($this->Target->TargetOwner->deleteAll($conditions)){
				return json_encode(array('success'=>true,'message'=>''));
			}
		}
		return json_encode(array('success'=>false,'message'=>'An error occured'));
	}

	/**
	* Signifies that the creator of the target has finish editing the details
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function finalize_target(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$owners = $this->Target->TargetOwner->find('count',array('conditions'=>array('TargetOwner.target_id'=>$this->request->data['target_id'])));
			if(!$owners){
				$new_assignment = array(
					'target_id'=>$this->request->data['target_id'],
					'employee_id'=>$this->Auth->user('id'),
					'assigned_by'=>$this->Auth->user('id')
				);
				try{
					$this->Target->TargetOwner->save($new_assignment);
				}
				catch(Exception $e){
					return json_encode(array('success'=>false,'message'=>'An error occurred'));
				}
			}
			$updates = array(
				'id'=>$this->request->data['target_id'],
				'finalized'=>true
			);

			if($this->Target->save($updates)){
				//Lets go up the tree and update the completion of parent targets
				$parent_id = $this->Target->field('parent_id',array('id'=>$updates['id']));
				if($parent_id){
					$parent = $this->Target->findById($parent_id);
					$this->Target->calcProgressBaseOnTarget($parent['Target']);
				}
				return json_encode(array('success'=>true,'message'=>''));
			}
		}
		return json_encode(array('success'=>false,'message'=>'An error occurred'));
	}

	/**
	* Removes or Deletes a target
	* This triggers other events such as removing sub targets, 
	* removing assignments/owners and flagging work plans with sub targets as modified
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function remove(){
		$this->autoRender = FALSE;

		if(!empty($this->request->data)){
			if($this->Target->delete($this->request->data['target_id'])){
				return json_encode(array('success'=>true,'message'=>''));
			}
		}
		return json_encode(array('success'=>false,'message'=>'A random error occured'));
	}

	/**
	* View the information for a target
	*
	* @return void
	*/
	public function view(){
		if(!empty($this->request->named)){
			$target_id = $this->request->named['target_id'];	

			$status_options = ClassRegistry::init('PmapTargetStatusOption')->find('list',array('fields'=>array('name','name')));
			$percentage_values = array(0=>'0%',5=>'5%',10=>'10%',15=>'15%',20=>'20%',25=>'25%',30=>'30%',35=>'35%',40=>'40%',45=>'45%',50=>'50%',55=>'55%',60=>'60%',65=>'65%',70=>'70%',75=>'75%',80=>'80%',85=>'85%',90=>'90%',95=>'95%',100=>'Complete');
			$target = $this->Target->getTargetWithSubs($target_id);
			$employee_id = $this->Auth->user('id');
			
			$this->set(compact('target','percentage_values','status_options','employee_id'));
		}
	}
	
	/**
	* Add progress updates to a target that are submitted by the owner
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function update_target(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$data = $this->request->data['TargetUpdate'];
			$target = $this->Target->findById($data['target_id']);
			$target = $target['Target'];

			if($data['status']=='Deferred' or $data['status']=='Paused'){
				$new_update['status'] = $data['status'];
			}

			$new_update = array();
			if($target['calculation_type']=='Percentage'){
				$new_update['percentage_completion_ytd'] = $data['new_percentage_value'];
				$target['percentage_completion_ytd'] = $data['new_percentage_value'];
			}elseif($target['calculation_type']=='Currency'){
				$new_update['currency_ytd'] = $data['currency_ytd'];
				$target['currency_ytd'] = $data['currency_ytd'];
			}elseif($target['calculation_type']=='Direct Value'){
				$new_update['value_ytd'] = $data['value_ytd'];
				$target['value_ytd'] = $data['value_ytd'];
			}elseif($target['calculation_type']=='Ratio'){
				$new_update['ytd_ratio_from'] = $data['ytd_ratio_from'];
				$new_update['ytd_ratio_to'] = $data['ytd_ratio_to'];

				$target['ytd_ratio_from'] = $data['ytd_ratio_from'];
				$target['ytd_ratio_to'] = $data['ytd_ratio_to'];
			}
			
			$progress = $this->Target->calcProgressRawTarget($target);

			$target['completion'] = $progress['current_progress'];
			$target['progress_flag'] = $progress['progress_flag'];
			$target['ideal_progress'] = $progress['ideal_progress'];

			$new_update['completion'] = $progress['current_progress'];
			$new_update['progress_flag'] = $progress['progress_flag'];
			$new_update['ideal_progress'] = $progress['ideal_progress'];

			$target = $new_update;
			$target['id'] = $data['target_id'];

			$this->Target->save($target);

			$new_update['added'] = date('Y-m-d');
			$new_update['comment'] = $data['comment'];
			$new_update['target_id'] = $data['target_id'];

			$this->Target->TargetUpdate->save($new_update);
			//Calculate progress of update, of Kpi, of Target, of Target Parent and up the ladder
			//Lets go up the tree and update the completion of parent targets

			$parent_id = $this->Target->field('parent_id',array('id'=>$target['id']));
			if($parent_id){
				$parent = $this->Target->findById($parent_id);
				$this->Target->calcProgressBaseOnTarget($parent['Target']);
			}

			return json_encode(array('success'=>true,'message'=>''));
		}
		return json_encode(array('success'=>false,'message'=>'An error occurred'));
	}

	/**
	* Update weightings of targets of a parent target
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function update_weightings(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$weightings = $this->request->data['Weighting'];
			$w_sum = 0;
			$updates = array();
			foreach($weightings['target_id'] as $k=>$target){
				$w_sum += $weightings['weighting_of_parent'][$k];
				$updates[] = array(
					'id'=>$target,
					'weighting_of_parent_target'=>$weightings['weighting_of_parent'][$k]
				);
			}
			if($w_sum!=100){
				return json_encode(array('success'=>false,'message'=>'Weightings do not add up to 100%'));		
			}else{
				if($this->Target->saveMany($updates)){
					$this->Target->udpateParentProgress($weightings['parent_id']);
					return json_encode(array('success'=>true,'message'=>''));		
				}
			}
		}
		return json_encode(array('success'=>false,'message'=>''));
	}

	/**
	* View a speficied KPI
	*
	* @return void
	*/
	public function view_kpi(){
		$percentage_values = array(0=>'0%',5=>'5%',10=>'10%',15=>'15%',20=>'20%',25=>'25%',30=>'30%',35=>'35%',40=>'40%',45=>'45%',50=>'50%',55=>'55%',60=>'60%',65=>'65%',70=>'70%',75=>'75%',80=>'80%',85=>'85%',90=>'90%',95=>'95%',100=>'100%');
		$kpi = $this->Target->TargetKpi->getKpi($this->request->named['kpi_id']);
		$this->set(compact('percentage_values','kpi'));
	}

	/**
	* Add progress updates to a specified KPI
	*
	* @return array 	Contains success index in json array that specifies if action was successful or not
	*					If Success was not successful message index will contain the information for why not
	*/
	public function update_kpi(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$data = $this->request->data['TargetKpiUpdate'];
			$kpi = $this->Target->TargetKpi->findById($data['target_kpi_id']);
			$kpi = $kpi['TargetKpi'];

			$new_update = array();
			
			if($kpi['type']=='Percentage'){
				$new_update['percentage_completion_ytd'] = $data['new_percentage_value'];
				$kpi['percentage_completion_ytd'] = $data['new_percentage_value'];
			}elseif($kpi['type']=='Currency'){
				$new_update['currency_ytd'] = $data['currency_ytd'];
				$kpi['currency_ytd'] = $data['currency_ytd'];
			}elseif($kpi['type']=='Direct Value'){
				$new_update['value_ytd'] = $data['value_ytd'];
				$kpi['value_ytd'] = $data['value_ytd'];
			}elseif($kpi['type']=='Ratio'){
				$new_update['ytd_ratio_from'] = $data['ytd_ratio_from'];
				$new_update['ytd_ratio_to'] = $data['ytd_ratio_to'];

				$kpi['ytd_ratio_from'] = $data['ytd_ratio_from'];
				$kpi['ytd_ratio_to'] = $data['ytd_ratio_to'];
			}
			$target = $this->Target->findById($kpi['target_id']);
			$kpi['start_date'] = $target['Target']['start_date'];
			$kpi['due_date'] = $target['Target']['due_date'];

			$progress = $this->Target->calcProgressRawKpi($kpi);
			$kpi['completion'] = $progress['current_progress'];
			$kpi['progress_flag'] = $progress['progress_flag'];
			$kpi['ideal_progress'] = $progress['ideal_progress'];

			$new_update['completion'] = $progress['current_progress'];
			$new_update['progress_flag'] = $progress['progress_flag'];
			$new_update['ideal_progress'] = $progress['ideal_progress'];

			$kpi = $new_update;
			$kpi['id'] = $data['target_kpi_id'];
			
			$this->Target->TargetKpi->save($kpi);

			$new_update['added'] = date('Y-m-d');
			$new_update['comment'] = $data['comment'];
			$new_update['target_kpi_id'] = $data['target_kpi_id'];

			ClassRegistry::init('Pmap.TargetKpiUpdate')->save($new_update);

			//Calculate progress of update, of Kpi, of Target, of Target Parent and up the ladder
			$this->Target->calcProgressBaseOnKpi($target['Target']);

			return json_encode(array('success'=>true,'message'=>''));
		}
		return json_encode(array('success'=>false,'message'=>'An error occurred'));
	}

	/**
	* Search for a specific employee 
	* 
	* @return void
	*/
	public function emp_search(){
		$searchTerm = $this->request->data['search_term'];
		$employee_type = $this->request->named;
		$searchTerms = explode(' ', $searchTerm);
		$searchResults = $this->Target->employeeSearch($searchTerm,$employee_type);
		
		$this->set('search_results',$searchResults);
	}

	/**
	* Update the weighting of an objective
	*
	* @return array 	Json array with indexes success (true if update was successful) and message (any explanation of failure or other)
	*/
	public function update_weighting_obj(){
		$this->autoRender = FALSE;
		if(!empty($this->request->data)){
			$obj = array(
				'id'=>$this->request->data['obj_id'],
				'weighting_of_parent_target'=>$this->request->data['weighting']
			);
			if($this->Target->save($obj)){
				return array('success'=>true,'message'=>'');
			}
		}
		return array('success'=>false,'message'=>'An error occured');
	}
}