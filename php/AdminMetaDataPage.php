<?php

class AdminMetaDataPage extends BasePageController {
	
	public function defaultAction() {
		
		$this->handleAjax();
		$this->handleGet();
		
		$em = $this->getEntityManager();
		
		$this->twigVars['types'] = EntityBase::selectDistinct($em, 'MetaData', 'type');
		$this->twigVars['valueTypes'] = EntityBase::selectDistinct($em, 'MetaData', 'valueType');
		$this->twigVars['valueSubTypes'] = EntityBase::selectDistinct($em, 'MetaData', 'valueSubType');
		$this->twigVars['audiences'] = EntityBase::selectDistinct($em, 'MetaData', 'audience');
		$this->twigVars['sources'] = EntityBase::selectDistinct($em, 'MetaData', 'source');
		
		if (!empty($this->get['valueType'])) {
			$valueTypes = explode(',', $this->get['valueType']);
			$em = $this->getEntityManager();
			$this->twigVars['valueSubTypes'] = MetaData::selectDistinct($em, 'MetaData', 'valueSubType', array('valueType' => $valueTypes));
		}
		
		$this->twigVars['metaData'] = $this->getMetaData();
		
		$this->twig->display('admin-metadata.twig', $this->twigVars);
	}
	
	private function handleGet() {
		if (!empty($this->get)) {
			$this->twigVars['urlParams'] = $this->get;
		}
	}
	
	private function handleAjax() {
		if (!empty($this->post) && !empty($this->post['action'])) {
			switch ($this->post['action']) {
				case 'filter-change':
					$em = $this->getEntityManager();
					switch ($this->post['filter']) {
						case 'valueSubType':
							$response = array(
								'filter' => 'valueSubType',
								'value' => $this->post['value']
							);
							
							if ($this->post['value'] == 'any') {
								$response['values'] = MetaData::selectDistinct($em, 'MetaData', 'valueSubType');
							}
							else {
								$response['values'] = MetaData::selectDistinct($em, 'MetaData', 'valueSubType', array('valueType' => $this->post['value']));
							}
							
							echo(json_encode($response));
							break;
					}
					break;
				case 'get-entity':
					$em = $this->getEntityManager();
					$entity = null;
					if ($this->post['type'] == 'employee') {
						$entity = $em->getRepository('Employee')->findOneByEmployeeID($this->post['typeID']);
					}
					else if ($this->post['type'] == 'business') {
						$entity = $em->getRepository('Business')->findOneByBusinessID($this->post['typeID']);
					}
					echo(json_encode($entity));
					break;
			}
			
			exit();
		}
	}
	
	private function getMetaData() {
		$em = $this->getEntityManager();
		
		$filters = array();
		$refl = new ReflectionClass('MetaData');
		foreach ($this->get as $field => $values) {
			$values = explode(',', $values);
			
			try {
				// This throws and exception if $field is not a property of MetaData
				$refl->getProperty($field);
				
				foreach ($values as $value) {
					$filters[$field][] = $value;
				}
			}
			catch (Exception $e) {
				continue;
			}
		}
		
		$qb = $em->createQueryBuilder();
		//$qb->select('md')->from('MetaData' , 'md');
		$qb->select('md', 'e.employeeID', 'e.firstName', 'e.lastName', 'b.businessID', 'b.displayName')
			->from('MetaData' , 'md')
			->leftJoin('Employee', 'e', \Doctrine\ORM\Query\Expr\Join::WITH, "md.type = 'employee' AND md.typeID = e.employeeID")
			->leftJoin('Business', 'b', \Doctrine\ORM\Query\Expr\Join::WITH, "md.type = 'business' AND md.typeID = b.businessID");
		
		// Add filters
		foreach ($filters as $field => $values) {
			$sql = '';
			foreach ($values as $value) {
				$sql .= "md.$field = '$value' OR ";
			}
			$sql = rtrim($sql, ' OR ');
			
			$qb->andWhere($sql);
		}
		
		$filterBusinesses = false;
		if (!isset($filters['type']) || in_array('business', $filters['type'])) {
			$filterBusinesses = true;
		}
		
		$filterEmployees = false;
		if (!isset($filters['type']) || in_array('employee', $filters['type'])) {
			$filterEmployees = true;
		}
		
		// Process search phrase
		$businesses = array();
		$employees = array();
		$merged = array();
		if (!empty($this->get['search'])) {
			$ds = new NAHDirectoryService(true);
			
			if ($filterBusinesses) {
				$data = array(
					'search-phrase' => $this->get['search'], 
					'max-results' => 1000, 
					'response-fields' => array('businessID', 'displayName'), 
					'include-inactive' => true, 
					'only-inactive' => false
				);
				$businesses = $ds->searchBusinesses($data);
			}
			
			if ($filterEmployees) {
				$data = array(
					'search-phrase' => $this->get['search'],
					'max-results' => 1000,
					'response-fields' => array('employeeID', 'firstName', 'lastName'),
					'include-inactive' => true,
					'only-inactive' => false
				);
				$employees = $ds->searchEmployees($data);
			}
			
			// Merge the search results and sort them by weight
			$merged = array_merge($businesses, $employees);
			usort($merged, function($a, $b) {
				if ($a['weight'] == $b['weight']) {
					return 0;
				}
				else if ($a['weight'] > $b['weight']) {
					return -1;
				}
				else if ($a['weight'] < $b['weight']) {
					return 1;
				}
			});
			
			if (count($businesses) > 0 || count($employees) > 0) {
				$inFilter = 'md.typeID IN (';
				foreach ($businesses as $business) {
					$inFilter .= $business['businessID'] . ',';
				}
				foreach ($employees as $employee) {
					$inFilter .= $employee['employeeID'] . ',';
				}
				$inFilter = rtrim($inFilter, ',');
				$inFilter .= ')';
				
				$qb->andWhere($inFilter);
			}
			else {
				// No results from the search
				return array();
			}
		}
		else {
			$qb->addOrderBy('b.displayName', 'ASC');
			$qb->addOrderBy('e.lastName', 'ASC');
			$qb->addOrderBy('e.firstName', 'ASC');
			$qb->addOrderBy('md.valueType');
			$qb->addOrderBy('md.valueSubType');
			$qb->addOrderBy('md.valueOrder');
		}
		
		$qb->setMaxResults(1000);
		$metaData = $qb->getQuery()->getArrayResult();
		
		// Add businesses without meta data
		foreach ($businesses as $business) {
			if (array_search($business['businessID'], array_column($metaData, 'businessID')) === false) {
				$metaData[] = array(
					0 => array(),
					'businessID' => $business['businessID'],
					'displayName' => $business['displayName'],
					'employeeID' => null,
					'firstName' => null,
					'lastName' => null
				);
			}
		}
		
		// Add employees without meta data
		foreach ($employees as $employee) {
			if (array_search($employee['employeeID'], array_column($metaData, 'employeeID')) === false) {
				$metaData[] = array(
					0 => array(),
					'businessID' => null,
					'displayName' => null,
					'employeeID' => $employee['employeeID'],
					'firstName' => $employee['firstName'],
					'lastName' => $employee['lastName']
				);
			}
		}
		
		// If we are doing a search then sort the meta data according to the merged results (by weight)
		if (count($merged) > 0) {
			
			$mergedIDs = array();
			foreach ($merged as $m) {
				if (!empty($m['businessID'])) {
					$mergedIDs[] = $m['businessID'];
				}
				else if (!empty($m['employeeID'])) {
					$mergedIDs[] = $m['employeeID'];
				}
			}
			
			usort($metaData, function ($a, $b) use ($mergedIDs) {
				$aId = null;
				if (!empty($a['businessID'])) {
					$aId = $a['businessID'];
				}
				else if (!empty($a['employeeID'])) {
					$aId = $a['employeeID'];
				}
				
				$bId = null;
				if (!empty($b['businessID'])) {
					$bId = $b['businessID'];
				}
				else if (!empty($b['employeeID'])) {
					$bId = $b['employeeID'];
				}
				
				$aIndex = array_search($aId, $mergedIDs);
				$bIndex = array_search($bId, $mergedIDs);
				if ($aIndex == $bIndex) {
					if (!empty($a[0]) && !empty($b[0])) {
						if ($a[0]['valueType'] == $b[0]['valueType']) {
							if ($a[0]['valueOrder'] == $b[0]['valueOrder']) {
								return 0;
							}
							else if ($a[0]['valueOrder'] > $b[0]['valueOrder']) {
								return 1;
							}
							else if ($a[0]['valueOrder'] < $b[0]['valueOrder']) {
								return -1;
							}
						}
						else if ($a[0]['valueType'] > $b[0]['valueType']) {
							return 1;
						}
						else if ($a[0]['valueType'] < $b[0]['valueType']) {
							return -1;
						}
					}
					else {
						return 0;
					}
				}
				else if ($aIndex > $bIndex) {
					return 1;
				}
				else if ($aIndex < $bIndex) {
					return -1;
				}
			});
		}
		
		return $metaData;
	}
	
	public static function joinMetaDataValues($values) {
		$string = '';
		if (is_array($values)) {
			foreach ($values as $name => $value) {
				$string .= "$name:$value,";
			}
			$string = rtrim($string, ',');
		}
		else {
			$string = $values;
		}
		return $string;
	}
	
	public static function truncate($string, $size) {
		if (strlen($string) < $size) {
			return $string;
		}
		else {
			$temp = str_split($string, $size);
			return array_shift($temp) . "...";
		}
	}
}