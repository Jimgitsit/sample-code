<?php

use \Gozer\Core\CoreAPI;
use \Gozer\Core\CoreAPIResponseJSON;
use \Gozer\Core\CoreAPIResponseDefault;
use \NAHDS\WeightedSearch;
use NAHDS\NAHLDAP;
use \PHPMailer\PHPMailer\PHPMailer;

/**
 * Directory service API controller.
 */
class NAHDirectoryService extends CoreAPI
{
	private $returnResult = false;
	
	private static $searchScopes = array(
		'providers',
		'employees',
		'businesses'
	);
	
	private static $searchAbbreviations = array(
		'dept'          => 'department',
		'med'           => 'medicine',
		'department'    => 'dept',
		'jim'           => 'james',
		'will'          => 'william',
		'bill'          => 'william'
	);
	
	const DEFAULT_MAX_SEARCH_RESULTS = 20;
	
	/**
	 * NAHDirectoryService constructor.
	 * 
	 * @documen nodoc
	 */
	public function __construct($returnResult = false) {
		$this->returnResult = $returnResult;
		try {
			if ($this->returnResult) {
				$this->setResponder(new CoreAPIResponseDefault());
			}
			else {
				$this->setResponder(new CoreAPIResponseJSON());
			}
			parent::__construct();
		}
		catch (Exception $e) {
			return $this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Returns API version.
	 * 
	 * @documen nodoc
	 */
	public function defaultAction()
	{
		$response = array(
			'api' => 'NAH Directory Service',
			'version' => API_VERSION
		);

		return $this->respond($response);
	}

	/**
	 * Request an OAuth2 token.
	 * 
	 * Responds with an access token that must be used in the URL for subsequent requests. For example:
	 * 
	 * `/api/get-all-providers?access_token=18c685e1f56aa811f20b27a81285decce2fbc463`
	 * 
	 * The token will expire after a set amount of time, therefore you must check the response of every request 
	 * for the error "token expired" at which time you must request a new token.
	 * 
	 * Method: POST
	 * 
	 * Request format:
	 * `/api/authorize`
	 * 
	 * POST data:
	 * ```
	 * {
	 *      "grant_type": "client_credentials",
	 *      "client_id": your-client-id,
	 *      "client_secret": your-client-secret
	 * }
	 * ```
	 * 
	 * Sample response:
	 * ```
	 * {
	 *      "access_token":"666cfc5743df77624a27e6766f5ae9bd334df461",
	 *      "expires_in":3600,
	 *      "token_type":"Bearer",
	 *      "scope":null
	 * }
	 * ```
	 * 
	 */
	public function authorize() {
		return $this->getOAuth2Token();
	}
	
	/**
	 * Returns basic info for all providers (providerNPI). test
	 * 
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-all-providers`
	 * 
	 * @return array
	 */
	public function getAllProviders() {
		$em = $this->getEntityManager();
		$providers = $em->createQueryBuilder()
			->select('e.providerNPI')
			->from('Provider', 'e')
			->orderBy('e.employeeID')
			->getQuery()
			->getResult();
		
		$response = array(
			"providers" => $providers
		);
		
		return $this->respond($response);
	}
	
	/**
	 * Returns the provider with the given NPI. 
	 * 
	 * Only basic info is returned by the GET version. For additional info use the POST 
	 * version with the additional info parameter.
	 * 
	 * Method: GET|POST
	 * 
	 * Request format:
	 * `/api/get-provider-by-npi/[NPI]`
	 * 
	 * POST data (optional):
	 * ```
	 * {
	 *      "additional_info": ["offices","licenses","education","appointments"]
	 * }
	 * ```
	 * 
	 * @param $npi
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function getProviderByNPI($npi) {
		$data = $this->getPostData();
		
		$em = $this->getEntityManager();
		$provider = $em->getRepository('Provider')->findOneByProviderNPI($npi);
		
		if (empty($provider)) {
			return $this->respondError("Invalid provider NPI", 400);
		}
		
		$response = $provider;
		
		if (!empty($data['additional_info'])) {
			if (!is_array($data['additional_info'])) {
				return $this->respondError("additional_info parameter must be an array", 400);
			}
			
			foreach ($data['additional_info'] as $type) {
				switch ($type) {
					case 'offices':
						// Get all the provider offices
						$query = $em->createQueryBuilder()
							->select('po')
							->from('ProviderOffices', 'po')
							->where('po.providerNPI = :npi')
							->setParameter('npi', $npi)
							->getQuery();
						$offices = $query->getArrayResult();
						$response->offices = $offices;
						
						// Get the office info for each office
						foreach ($response->offices as &$office) {
							$query = $em->createQueryBuilder()
								->select('o')
								->from('Office', 'o')
								->where('o.officeID = :officeID')
								->setParameter('officeID', $office['officeID'])
								->getQuery();
							$officeInfo = $query->getArrayResult();
							if (!empty($officeInfo)) {
								$office = array_merge($office, $officeInfo[0]);
							}
						}
						break;
					case 'licenses':
						$licenses = $em->getRepository('ProviderLicenses')->findByProviderNPI($npi);
						$response->licenses = $licenses;
						break;
					case 'education':
						$education = $em->getRepository('ProviderEducation')->findByProviderNPI($npi);
						$response->education = $education;
						break;
					case 'appointments':
						$appointments = $em->getRepository('ProviderAppointment')->findByProviderNPI($npi);
						$response->appointments = $appointments;
						break;
				}
			}
		}
		
		return $this->respond($response);
	}
	
	/**
	 * Returns the employee with the given ID.
	 *
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-employee-by-id/[ID]`
	 *
	 * @param $id
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function getEmployeeById($id) {
		$em = $this->getEntityManager();
		$employee = $em->getRepository('Employee')->findOneByEmployeeID($id);
		if (empty($employee)) {
			return $this->respondError("Invalid employee ID", 400);
		}
		
		$response = $employee;
		
		// Add meta data
		$metaData = $em->getRepository('MetaData')->findBy(array(
			'type' => 'employee',
			'typeID' => $employee->getEmployeeID()
		), array(
			'valueOrder' => 'ASC'
		));
		$response->meta_data = $metaData;
		
		return $this->respond($response);
	}
	
	/**
	 * Returns the employee with the given userName.
	 *
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-employee-by-username/[userName]`
	 *
	 * @param $userName
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function getEmployeeByUserName($userName) {
		$em = $this->getEntityManager();
		/** @var Employee $employee */
		$employee = $em->getRepository('Employee')->findOneByUserName($userName);
		if (empty($employee)) {
			return $this->respondError("Employee with userName $userName not found", 400);
		}
		
		$response = $employee;
		
		// Add meta data
		$metaData = $em->getRepository('MetaData')->findBy(array(
			'type' => 'employee',
			'typeID' => $employee->getEmployeeID()
		), array(
			'valueOrder' => 'ASC'
		));
		$response->meta_data = $metaData;
		
		return $this->respond($response);
	}
	
	/**
	 * Returns and array of employees belonging to the given cost center.
	 *
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-employees-by-cost-center/[cost center]`
	 * 
	 * @param $costCenter
	 * 
	 * @return array
	 */
	public function getEmployeesByCostCenter($costCenter) {
		$em = $this->getEntityManager();
		$employees = $em->getRepository('Employee')->findByCostCenter($costCenter);
		
		foreach ($employees as &$employee) {
			$metaData = $em->getRepository('MetaData')->findBy(array(
				'type' => 'employee',
				'typeID' => $employee->getEmployeeID()
			), array(
				'valueOrder' => 'ASC'
			));
			$employee->meta_data = $metaData;
		}
		
		return $this->respond($employees);
	}
	
	/**
	 * Updates the employee record given by employeeID. If employeeID is not found then a new employee is created.
	 * Responds with the full employee object. 
	 * 
	 * Either employeeID or userName is required. If only userName is supplied and no employeeID, the employeeID will 
	 * extrapolated from the userName if possible.
	 * 
	 * Phone numbers will automatically be populated from AD. Also, if any of the following fields are not provided they 
	 * will automatically be obtained from AD if available:
	 *      firstName
	 *      lastName
	 *      title
	 *      email
	 *
	 * Method: POST
	 *
	 * Request format:
	 * `/api/save-employee`
	 *
	 * POST data:
	 * ```
	 * {
	 *      employeeID
	 *      lastName: '',
 	 *      firstName: '',
 	 *      email: '',
 	 *      userName: '',
 	 *      positionCode: '',
 	 *      title: '',
 	 *      propertyID: '',
 	 *      property: '',
 	 *      costCenter: '',
 	 *      hireDate: '', (any value understood by the php DateTime constructor)
 	 *      terminationDate: '', (any value understood by the php DateTime constructor)
 	 *      birthDate: '', (format: mm/dd)
 	 *      supervisorEmployeeID: '',
 	 *      directorEmployeeID: '',
 	 *      vpEmployeeId: '',
 	 *      source: '',
	 *      email_notify: [true,false]
	 * }
	 * ```
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function saveEmployee() {
		$data = $this->getPostData();
		
		if (empty($data)) {
			return $this->respondError("Invalid json in POST data.");
		}
		
		$em = $this->getEntityManager();
		
		if (empty($data['employeeID'])) {
			
			// Try to extrapolate the employeeID from userName
			$success = false;
			if (!empty($data['userName'])) {
				$empId = filter_var($data['userName'], FILTER_SANITIZE_NUMBER_INT);
				$empId = str_replace(array('+','-'), '', $empId);
				if (!empty($empId) && is_numeric($empId)) {
					$data['employeeID'] = $empId;
					$success = true;
				}
				
				// If all we got was a username, make sure that username doesn't already exist
				if ($success && count($data) == 2) {
					$emp = $em->getRepository('Employee')->findOneByUserName($data['userName']);
					if (!empty($emp)) {
						// Username is already in use and that's all we got in the POST so return an error
						return $this->respondError("Username " . $data['userName'] . " is already in use by employee with ID " . $emp->getEmployeeID());
					}
				}
			}
			
			if (!$success) {
				// TODO: send an email?
				
				
				return $this->respondError("Missing 'employeeID' and could not extrapolate from userName.", 400);
			}
		}
		
		$emp = $em->getRepository('Employee')->findOneByEmployeeID($data['employeeID']);
		$isNew = false;
		if (empty($emp)) {
			$emp = new Employee();
			$emp->setCreated(new DateTime());
			if (empty($data['source'])) {
				$emp->setSource('User');
			}
			$isNew = true;
		}
		
		if ($isNew) {
			// Validate required fields for new records
			// TODO: Confirm required fields
			$required = array(
				'employeeID',
				'userName',
				//'costCenter',
				//'firstName',
				//'lastName',
				//'email',
				//'title'
			);
			
			$msg = 'Missing the following required field(s): ';
			$fail = false;
			foreach ($required as $field) {
				if (empty($data[$field])) {
					$fail = true;
					$msg .= $field . ', ';
				}
			}
			$msg = rtrim($msg, ', ');
			
			if ($fail) {
				return $this->respondError($msg, 400);
			}
		}
		
		foreach ($data as $field => $value) {
			
			if ($field == 'costCenter') {
				if (empty($value)) {
					$value = '';
				}
				else {
					if (!$emp->entityExists($em, 'Business', 'costCenter', $value)) {
						return $this->respondError("Unknown cost center $value.");
					}
				}
			}
			
			if ($field == 'supervisorID') {
				if (empty($value)) {
					$value = '';
				}
				else {
					if (!$emp->entityExists($em, 'Employee', 'employeeID', $value)) {
						return $this->respondError("Supervisor with ID $value does not exist.");
					}
				}
			}
			
			if ($field == 'directorID') {
				if (empty($value)) {
					$value = '';
				}
				else {
					if (!$emp->entityExists($em, 'Employee', 'employeeID', $value)) {
						return $this->respondError("Director with ID $value does not exist.");
					}
				}
			}
			
			if ($field == 'vpID') {
				if (empty($value)) {
					$value = '';
				}
				else {
					if (!$emp->entityExists($em, 'Employee', 'employeeID', $value)) {
						return $this->respondError("VP with ID $value does not exist.");
					}
				}
			}
			
			if ($field == 'hireDate') {
				if (empty($value)) {
					$value = null;
				}
				else {
					$value = new DateTime($value);
				}
			}
			
			if ($field == 'terminationDate') {
				if (empty($value)) {
					$value = null;
				}
				else {
					$value = new DateTime($value);
				}
			}
			
			$emp->setEntityProperty($field, $value);
		}
		
		$emp->setLastUpdated(new DateTime());
		
		$em->persist($emp);
		$em->flush();
		
		// Get metadata from AD for new users
		$nahldap = new NAHLDAP();
		$adUser = $nahldap->findByUserName($emp->getUserName());
		if ($adUser !== null) {
			Util::addMetaPhone($em, $emp->getEmployeeID(), $adUser['phonenumbers']);
			
			if (empty($data['firstName'])) {
				if (!empty($adUser['givenname'][0])) {
					$emp->setFirstName(trim($adUser['givenname'][0]));
				}
			}
			
			if (empty($data['lastName'])) {
				if (!empty($adUser['sn'][0])) {
					$emp->setLastName(trim($adUser['sn'][0]));
				}
			}
			
			if (empty($data['title'])) {
				if (!empty($adUser['title'][0])) {
					$emp->setTitle(trim($adUser['title'][0]));
				}
			}
			
			if (empty($data['email'])) {
				// TODO: This should be in meta data
				if (!empty($adUser['mail'][0])) {
					$emp->setEmail(trim($adUser['mail'][0]));
				}
			}
			
			$em->flush();
		}
		
		$response = $emp;
		
		// Add meta data to the response
		$metaData = $em->getRepository('MetaData')->findBy(array(
			'type' => 'employee',
			'typeID' => $emp->getEmployeeID()
		), array(
			'valueOrder' => 'ASC'
		));
		$response->meta_data = $metaData;
		
		// Notification email
		if ($isNew && !empty($data['email_notify']) && $data['email_notify'] == true) {
			$mail = new PHPMailer;
			$mail->isSMTP();
			$mail->Host = SMTP_HOST;
			$mail->Port = SMTP_PORT;
			$mail->SMTPAutoTLS = false;
			$mail->setFrom(SMTP_LOG_FROM_ADDRESS, SMTP_LOG_FROM_NAME);
			
			$toAddys = explode(',', SMTP_LOG_TO_ADDRESS);
			$toNames = explode(',', SMTP_LOG_TO_NAME);
			
			/*
			// TODO: What?
			if (count($toAddys) != count($toNames)) {
				//Log::getLogger()->error("Different number of values for SMTP_LOG_TO_ADDRESS and SMTP_LOG_TO_NAMES");
				//return;
			}
			*/
			
			for ($i = 0; $i < count($toAddys); $i++) {
				$mail->addAddress($toAddys[$i], $toNames[$i]);
			}
			
			$mail->Subject = "New employee added via directory API";
			
			$body = 'Employee ID: ' . $emp->getEmployeeID() . '<br/>Username: ' . $emp->getUserName() . '<br/>Name: ' . $emp->getFirstName() . ' ' . $emp->getLastName() . '<br/>Title: ' . $emp->getTitle() . '<br/>Email: ' . $emp->getEmail();
			$mail->msgHTML($body);
			
			//send the message, check for errors
			if (!$mail->send()) {
				//echo "Mailer Error: " . $mail->ErrorInfo;
				//Log::getLogger()->info("Error sending email: " . $mail->ErrorInfo);
				// TODO: What?
			}
			
		}
		
		return $this->respond($response);
	}
	
	/**
	 * Removes an employee permanently.
	 *
	 * Method: POST
	 *
	 * Request format:
	 * `/api/remove-employee`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "employeeID": 123456 (required)
	 * }
	 * ```
	 * @return array
	 * @throws Exception
	 * 
	 */
	public function removeEmployee() {
		$data = $this->getPostData();
		
		if (empty($data['employeeID'])) {
			return $this->respondError("Missing employeeID.");
		}
		
		$em = $this->getEntityManager();
		$employee = $em->getRepository('Employee')->findOneByEmployeeID($data['employeeID']);
		if (empty($employee)) {
			return $this->respondError("Invalid 'employeeID' {$data['employeeID']}. Entity does not exist.");
		}
		
		// Remove any meta data for the employee
		$metaData = $em->createQueryBuilder()
			->select('md')
			->from('MetaData', 'md')
			->where('md.type=:type')
			->andWhere('md.typeID=:id')
			->setParameter('type', 'employee')
			->setParameter('id', $employee->getEmployeeID())
			->getQuery()
			->getResult();
		
		foreach ($metaData as $md) {
			$em->remove($md);
		}
		
		$em->remove($employee);
		$em->flush();
		
		return $this->respond("success");
	}
	
	/**
	 * Returns basic info for all businesses (businessID, costCenter, name).
	 * 
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-all-businesses`
	 * 
	 * @return array
	 */
	public function getAllBusinesses() {
		$em = $this->getEntityManager();
		$businesses = $em->createQueryBuilder()
			->select('b.businessID,b.costCenter,b.name')
			->from('Business', 'b')
			->orderBy('b.name')
			->getQuery()
			->getResult();
		
		$response = array(
			"businesses" => $businesses
		);
		
		return $this->respond($response);
	}
	
	/**
	 * Returns basic info for all employees (employeeID and userName/LawsonID).
	 *
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-all-employees`
	 * 
	 * @return array
	 */
	public function getAllEmployees() {
		$em = $this->getEntityManager();
		$employees = $em->createQueryBuilder()
			->select('e.employeeID,e.userName')
			->from('Employee', 'e')
			->orderBy('e.employeeID')
			->getQuery()
			->getResult();
		
		$response = array(
			"employees" => $employees
		);
		
		return $this->respond($response);
	}
	
	/**
	 * Returns the business with the given ID including meta data and the children or parent business.
	 * 
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-business-by-id/[ID]`
	 * 
	 * @param $id
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function getBusinessById($id) {
		$em = $this->getEntityManager();
		/** @var $business Business */
		$business = $em->getRepository('Business')->findOneByBusinessID($id);
		if (empty($business)) {
			return $this->respondError("Invalid business ID", 400);
		}
		
		$response = $business;
		
		// Is parent?
		if ($business->getParentBusinessID() == 0) {
			// Get the children
			$children = $em->getRepository('Business')->findBy(array(
				'parentBusinessID' => $business->getBusinessID()
			));
			$response->children = $children;
		}
		else {
			// Get the parent
			if ($business->getParentBusinessID() != $business->getBusinessID()) {
				$parent = $em->getRepository('Business')->findOneBy(array(
					'businessID' => $business->getParentBusinessID()
				));
				$response->parent = $parent;
			}
		}
		
		// Add meta data
		$metaData = $em->getRepository('MetaData')->findBy(array(
			'type' => 'business',
			'typeID' => $business->getBusinessID()
		), array(
			'valueOrder' => 'ASC'
		));
		$response->meta_data = $metaData;
		
		return $this->respond($response);
	}
	
	/**
	 * Returns the business with the given cost center including meta data and the children or parent business.
	 *
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-business-by-id/[ID]`
	 *
	 * @param $costCenter
	 * 
	 * @return array
	 */
	public function getBusinessByCostCenter($costCenter) {
		$em = $this->getEntityManager();
		/** @var $business Business */
		$business = $em->getRepository('Business')->findOneByCostCenter($costCenter);
		if (empty($business)) {
			return $this->respondError("Unknown cost center", 400);
		}
		
		$response = $business;
		
		// Is parent?
		if ($business->getParentBusinessID() == 0) {
			// Get the children
			$children = $em->getRepository('Business')->findBy(array(
				'parentBusinessID' => $business->getBusinessID()
			));
			$response->children = $children;
		}
		else {
			// Get the parent
			if ($business->getParentBusinessID() != $business->getBusinessID()) {
				$parent = $em->getRepository('Business')->findOneBy(array(
					'businessID' => $business->getParentBusinessID()
				));
				$response->parent = $parent;
			}
		}
		
		// Add meta data
		$metaData = $em->getRepository('MetaData')->findBy(array(
			'type' => 'business',
			'typeID' => $business->getBusinessID()
		), array(
			'valueOrder' => 'ASC'
		));
		$response->meta_data = $metaData;
		
		return $this->respond($response);
	}
	
	/**
	 * Updates the business record given by businessID. If businessID is not found then a new business is created.
	 * Responds with the full business object.
	 * 
	 * Method: POST
	 * 
	 * Request format:
	 * `/api/save-business`
	 * 
	 * POST data:
	 * ```
	 * {
	 *      "businessID": "12345", (required. set to 'auto' to automatically generate.)
	 *      "costCenter": "14850",
	 *      "directions": "",
	 *      "directorID": "5223",
	 *      "employeePortal": false,
	 *      "hours": "",
	 *      "intranetURL": "",
	 *      "isActive": true,
	 *      "isBlind": false,
	 *      "isNew": true,
	 *      "midasID": "",
	 *      "name": "Education Department",
	 *      "parentBusinessID": "0",
	 *      "processLevel": "100",
	 *      "promoLine": "",
	 *      "property": "NAH",
	 *      "propertyLocation": "NAH",
	 *      "propertyReportsTo": "NAH",
	 *      "publicWebsite": true,
	 *      "type": "Department",
	 *      "vpID": "35850",
	 *      "webURL": "http://www.nahealth.com/education",
	 *      "source": ""
	 * }
	 * ```
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function saveBusiness() {
		$data = $this->getPostData();
		
		if (empty($data)) {
			return $this->respondError("Invalid json in POST data.");
		}
		
		if (empty($data['businessID'])) {
			return $this->respondError("Missing 'businessID'.", 400);
		}
		
		$em = $this->getEntityManager();
		
		// If businessID is 'auto' then generate a number 1 + the highest value starting with 5000
		if ($data['businessID'] == 'auto') {
			$data['businessID'] = $em->createQueryBuilder()
				->select('MAX(b.businessID)')
				->from('Business', 'b')
				->getQuery()
				->getSingleScalarResult() + 1;
			
			if ($data['businessID'] < 5000) {
				$data['businessID'] = 5000;
			}
		}
		
		$business = $em->getRepository('Business')->findOneByBusinessID($data['businessID']);
		$isNew = false;
		if (empty($business)) {
			$business = new Business();
			$isNew = true;
		}
		
		if ($isNew) {
			$business->setCreated(new DateTime());
			
			// Validate required fields for new records
			$required = array(
				'name',
				'displayName',
				'type'
			);
			
			$msg = 'Missing the following required field(s): ';
			$fail = false;
			foreach ($required as $field) {
				if (empty($data[$field])) {
					$fail = true;
					$msg .= $field . ', ';
				}
			}
			$msg = rtrim($msg, ', ');
			
			if ($fail) {
				return $this->respondError($msg, 400);
			}
		}
		
		foreach ($data as $field => $value) {
			
			// These fields are ignored
			if ($field == 'created' || $field == 'lastUpdated') {
				continue;
			}
			
			// Override isNew if it is given
			if ($field == 'isNew') {
				$isNew = $value;
				continue;
			}
			
			// Validation
			if ($field == 'parentBusinessID') {
				if (empty($value)) {
					$value = 0;
				}
				else {
					// Make sure the parent exists
					$parent = $em->getRepository('Business')->findOneByBusinessID($value);
					if (empty($parent)) {
						return $this->respondError("Parent business with ID $value does not exist.");
					}
					// Make sure the parent is not itself a child
					else if (!empty($parent->getParentBusinessID())) {
						return $this->respondError("Parent business ID $value (" . $parent->getDisplayName() . ") is not a parent business.");
					}
					
					// Make sure this business does not have children
					// (setting a parent on a business that has children will make all the children grandchildren)
					$children = $em->getRepository('Business')->findByParentBusinessID($business->getBusinessID());
					if (!empty($children)) {
						return $this->respondError("Can not set a parent on a department that has children.");
					}
					
					if ($business->getBusinessID() == $value) {
						return $this->respondError("The business can not be it's own parent.");
					}
				}
			}
			
			if ($field == 'directorID') {
				if (empty($value)) {
					$value = '';
				}
				else {
					if (!$business->entityExists($em, 'Employee', 'employeeID', $value)) {
						return $this->respondError("Director with ID $value does not exist.");
					}
				}
			}
			
			if ($field == 'vpID') {
				if (empty($value)) {
					$value = '';
				}
				else {
					if (!$business->entityExists($em, 'Employee', 'employeeID', $value)) {
						return $this->respondError("VP with ID $value does not exist.");
					}
				}
			}
			
			$business->setEntityProperty($field, $value);
		}
		
		$business->setIsNew($isNew);
		$business->setLastUpdated(new DateTime());
		
		$em->persist($business);
		$em->flush();
		
		return $this->respond($business);
	}
	
	/**
	 * Removes a business permanently.
	 *
	 * Method: POST
	 *
	 * Request format:
	 * `/api/remove-business`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "businessID": 123456 (required)
	 * }
	 * ```
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function removeBusiness() {
		$data = $this->getPostData();
		
		if (empty($data['businessID'])) {
			return $this->respondError("Missing businessID.");
		}
		
		$em = $this->getEntityManager();
		$business = $em->getRepository('Business')->findOneByBusinessID($data['businessID']);
		if (empty($business)) {
			return $this->respondError("Invalid 'businessID' {$data['businessID']}. Entity does not exist.");
		}
		
		// Remove any meta data for the employee
		$metaData = $em->createQueryBuilder()
			->select('md')
			->from('MetaData', 'md')
			->where('md.type=:type')
			->andWhere('md.typeID=:id')
			->setParameter('type', 'business')
			->setParameter('id', $business->getBusinessID())
			->getQuery()
			->getResult();
		
		foreach ($metaData as $md) {
			$em->remove($md);
		}
		
		$em->remove($business);
		$em->flush();
		
		return $this->respond("success");
	}
	
	/**
	 * Returns a single meta data record given its ID.
	 * 
	 * Method: GET
	 *
	 * Request format:
	 * `/api/get-metadata-by-id/[ID]`
	 * 
	 * @param $id
	 * 
	 * @return array
	 */
	public function getMetaDataById($id) {
		$em = $this->getEntityManager();
		$metaData = $em->getRepository('MetaData')->find($id);
		$response = $metaData;
		
		return $this->respond($response);
	}
	
	/**
	 * Saves a meta data record. If 'id' is not supplied in the post then a new 
	 * meta data record is created. Responds with the full meta data record.
	 * 
	 * Method: POST
	 * 
	 * Request format:
	 * `/api/save-metadata`
	 * 
	 * POST data (required):
	 * ```
	 * {
	 *     "id": 123456 (optional)
	 *     "source": "User" (required)
	 *     "type": "employee" or "business" (required)
	 *     "typeID": 654321 (required)
	 *     "valueType": "Phone", (required)
	 *     "valueSubtype": "Work", (optional)
	 *     "label": "Line 1", (optional)
	 *     "valueOrder": 5, (optional)
	 *     "audience": "Private", "Internal", or "Public", (required)
	 *     "isActive": true (optional)
	 * }
	 * ```
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function saveMetaData() {
		$data = $this->getPostData();
		
		if (empty($data)) {
			return $this->respondError("Invalid json in POST data.");
		}
		
		$em = $this->getEntityManager();
		
		$md = null;
		if (empty($data['id'])) {
			$md = new MetaData();
			$md->setCreated(new DateTime());
		}
		else {
			$md = $em->getRepository('MetaData')->find($data['id']);
			if (empty($md)) {
				return $this->respondError("Invalid id");
			}
		}
		
		$requiredFields = array(
			'source',
			'type',
			'typeID',
			'valueType',
			'audience',
			'value'
		);
		
		// Default value order
		if (empty($data['valueOrder'])) {
			$data['valueOrder'] = 0;
		}
		
		// Default source
		if (empty($data['source'])) {
			$data['source'] = 'User';
		}
		
		// Validation
		foreach ($requiredFields as $field) {
			if (!key_exists($field, $data)) {
				return $this->respondError("$field is required.");
			}
		}
		
		foreach ($data as $field => $value) {
			
			// These fields are ignored
			if ($field == 'created' || $field == 'lastUpdated') {
				continue;
			}
			
			// Validation
			if (in_array($field, $requiredFields) && empty($value)) {
				return $this->respondError("$field is required and can not be null.");
			}
			
			$md->setEntityProperty($field, $value);
		}
		
		// Make sure the entity for typeID exists
		$idField = '';
		switch ($data['type']) {
			case 'employee': $idField = 'employeeID'; break;
			case 'business': $idField = 'businessID'; break;
			case 'provider': $idField = 'providerNPI'; break;
		}
		if (!$md->entityExists($em, ucfirst($data['type']), $idField, $data['typeID'])) {
			return $this->respondError("Entity does not exist. {$data['type']}: {$data['typeID']}");
		}
		
		$md->setLastUpdated(new DateTime());
		
		$em->persist($md);
		$em->flush();
		
		return $this->respond($md);
	}
	
	/**
	 * Removes meta data permanently.
	 *
	 * Method: POST
	 *
	 * Request format:
	 * `/api/remove-meta-data`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "id": 123456 (required)
	 * }
	 * ```
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function removeMetaData() {
		$data = $this->getPostData();
		
		if (empty($data['id'])) {
			return $this->respondError("Missing id.");
		}
		
		$em = $this->getEntityManager();
		$md = $em->getRepository('MetaData')->find($data['id']);
		if (empty($md)) {
			return $this->respondError("Invalid 'id' {$data['id']}. Entity does not exist.");
		}
		
		$em->remove($md);
		$em->flush();
		
		return $this->respond("success");
	}
	
	/**
	 * Save a search phrase. If 'id' is not supplied in the post then a new
	 * search phrase is created. Responds with the full search phrase record.
	 * 
	 * Method: POST
	 *
	 * Request format:
	 * `/api/save-search-phrase`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "id": 123456 (optional)
	 *     "type": "employee" or "business" (required)
	 *     "typeID": 654321 (required)
	 *     "phrase": "some text" (required)
	 * }
	 * ```
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function saveSearchPhrase() {
		$data = $this->getPostData();
		
		if (empty($data)) {
			return $this->respondError("Invalid json in POST data.");
		}
		
		if (empty($data['phrase'])) {
			return $this->respondError("Missing 'phrase'.");
		}
		
		if (empty($data['type'])) {
			return $this->respondError("Missing 'type'.");
		}
		
		if (empty($data['typeId'])) {
			return $this->respondError("Missing 'typeId'.");
		}
		
		$em = $this->getEntityManager();
		
		$sp = null;
		if (!empty($data['id'])) {
			$sp = $em->getRepository('SearchPhrase')->find($data['id']);
			if (empty($sp)) {
				return $this->respondError("Bad id.");
			}
		}
		else {
			$sp = new SearchPhrase();
			$sp->setCreated(new DateTime());
		}
		
		$entityName = ucfirst($data['type']);
		$field = '';
		if ($entityName == 'Employee') {
			$field = 'employeeID';
		}
		else if ($entityName == 'Business') {
			$field = 'businessID';
		}
		else if ($entityName == 'Provider') {
			$field = 'providerNPI';
		}
		
		if (!$sp->entityExists($em, $entityName, $field, $data['typeId'])) {
			return $this->respondError("Invalid 'typeId' {$data['typeId']}. Entity does not exist.");
		}
		
		$sp->setPhrase($data['phrase']);
		$sp->setType($data['type']);
		$sp->setTypeId($data['typeId']);
		$sp->setLastUpdated(new DateTime());
		
		$em->persist($sp);
		$em->flush();
		
		return $this->respond($sp);
	}
	
	/**
	 * Removes a search phrase.
	 * 
	 * Method: POST
	 *
	 * Request format:
	 * `/api/remove-search-phrase`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "id": 123456 (required)
	 * }
	 * ```
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function removeSearchPhrase() {
		$data = $this->getPostData();
		
		if (empty($data)) {
			return $this->respondError("Invalid json in POST data.");
		}
		
		if (empty($data['id'])) {
			return $this->respondError("Missing id.");
		}
		
		$em = $this->getEntityManager();
		$sp = $em->getRepository('SearchPhrase')->find($data['id']);
		if (empty($sp)) {
			return $this->respondError("Invalid 'id' {$data['id']}. Entity does not exist.");
		}
		
		$em->remove($sp);
		$em->flush();
		
		return $this->respond("success");
	}
	
	/**
	 * Global search function.
	 * 
	 * Returns an array of results based on a search phrase and organized by scope.
	 * 
	 * For each of the values of "scope" you may optionally provide the fields you want 
	 * in the response, otherwise all fields are returned.
	 * 
	 * Happy coding!
	 * 
	 * 
	 * Method: POST
	 * 
	 * Request format:
	 * `/api/search`
	 * 
	 * POST data (required):
	 * ```
	 * {
	 *     "scope": ["all"] or one or more of ["providers", "employees", "businesses"],
	 *     "search-phrase": "b Barker"
	 *     "max-results": 20 (optional, default is 20)
	 *     "response-fields": { (optional)
	 *         "providers": [an array of fields to respond with],
	 *         "employees": [an array of fields to respond with],
	 *         "businesses": [an array of fields to respond with]
	 *     },
	 *     "include-inactive": false (optional, default is false),
	 *     "only-inactive": false (optional, default is false, overrides include-inactive)
	 * }
	 * ```
	 * 
	 * Example response with all fields:
	 * ```
	 * {
	 *     "providers": [
	 *         {
	 *             "providerNPI": "1467503920",
	 *             "lastName": "Kim",
	 *             "firstName": "Jin",
	 *             "middleName": "Koo",
	 *             "suffix": "",
	 *             "specialty1": "Nephrology",
	 *             "specialty2": "",
	 *             "specialty3": "",
	 *             "cellPhoneNumber": "(928) 499-2157",
	 *             "primaryEmail": "jkim@akdhc.com; credentialing@akdhc.com",
	 *             "sex": "F"
	 *         },
	 *         ...
	 *     ],
	 *     "employees": [
	 *         {
	 *             "employeeID": "35243",
	 *             "lastName": "KIM",
	 *             "firstName": "HEE WON",
	 *             "email": "kim@nahhealth.com",
	 *             "userName": "HK35243",
	 *             "positionCode": "PHYS829635E",
	 *             "title": "PHYSICIAN",
	 *             "propertyID": "80",
	 *             "property": "FLAGSTAFF",
	 *             "costCenter": "29635",
	 *             "hireDate": "2015-04-13",
	 *             "terminationDate": null,
	 *             "birthDate": "4\/10",
	 *             "supervisorEmployeeID": "17926",
	 *             "directorEmployeeID": "27345",
	 *             "vpEmployeeId": "29176"
	 *         },
	 *         ...
	 *     ],
	 *     "businesses": [
	 *         {
	 *             "businessID": "784",
	 *             "parentBusinessID": "779",
	 *             "costCenter": "",
	 *             "processLevel": "",
	 *             "propertyReportsTo": "",
	 *             "propertyLocation": "FMC",
	 *             "type": "Building",
	 *             "name": "FVSC - Education",
	 *             "directorID": "",
	 *             "vpID": "",
	 *             "property": "FLAGSTAFF",
	 *             "directions": "",
	 *             "intranetURL": "",
	 *             "webURL": "",
	 *             "hours": "",
	 *             "promoLine": "",
	 *             "created": "2014-03-31 15:14:15.0",
	 *             "lastUpdated": "2014-03-31 15:14:15.0",
	 *             "isBlind": "0",
	 *             "isActive": "1",
	 *             "publicWebsite": "No",
	 *             "employeePortal": "No",
	 *             "midasID": "",
	 *             "source": ""
	 *         },
	 *         ...
	 *     ]
	 * }
	 * ```
	 * 
	 * @var $data array
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function search($data = null) {
		if ($data === null) {
			$data = $this->getPostData();
			if ($data === null) {
				$error = json_last_error_msg();
				return $this->respondError("Invalid JSON in POST data. $error", 400);
			}
		}
		
		if (empty($data)) {
			return $this->respondError("Invalid json in POST data.");
		}
		
		if (empty($data['search-phrase'])) {
			return $this->respondError("Missing 'search-phrase' in the request data.", 400);
		}
		
		if (empty($data['scope'])) {
			return $this->respondError("Missing or invalid 'scope' in the request data.", 400);
		}
		
		if (!is_array($data['scope'])) {
			return $this->respondError("'scope' must be an array.", 400);
		}
		
		if (!empty($data['max-results']) && (!is_numeric($data['max-results']) || $data['max-results'] < 1)) {
			return $this->respondError("'max-results' should be numeric and greater than 1.");
		}
		
		if (in_array('all', $data['scope'])) {
			$data['scope'] = self::$searchScopes;
		}
		
		foreach ($data['scope'] as $scope) {
			if (!in_array($scope, self::$searchScopes)) {
				return $this->respondError("Invalid 'scope': $scope", 400);
			}
		}
		
		$results = array();
		
		if (in_array('providers', $data['scope'])) {
			$pData = $data;
			if (!empty($data['response-fields']['providers']) && is_array($data['response-fields']['providers'])) {
				$pData['response-fields'] = $data['response-fields']['providers'];
				unset($pData['response-fields']['providers']);
			}
			$ws = new NAHDirectoryService(true);
			$results['providers'] = $ws->searchProviders($pData);
		}
		
		if (in_array('employees', $data['scope'])) {
			$pData = $data;
			if (!empty($data['response-fields']['employees']) && is_array($data['response-fields']['employees'])) {
				$pData['response-fields'] = $data['response-fields']['employees'];
				unset($pData['response-fields']['employees']);
			}
			$ws = new NAHDirectoryService(true);
			$results['employees'] = $ws->searchEmployees($data);
		}
		
		if (in_array('businesses', $data['scope'])) {
			$pData = $data;
			if (!empty($data['response-fields']['businesses']) && is_array($data['response-fields']['businesses'])) {
				$pData['response-fields'] = $data['response-fields']['businesses'];
				unset($pData['response-fields']['businesses']);
			}
			$ws = new NAHDirectoryService(true);
			$results['businesses'] = $ws->searchBusinesses($data);
		}
		
		$resultTotal = 0;
		$resultTotals = array('resultCountTotal' => 0, 'resultCountProviders' => 0, 'resultCountEmployees' => 0, 'resultCountBusinesses' => 0);
		foreach ($data['scope'] as $scope) {
			$resultTotals['resultCountTotal'] += count($results[$scope]);
			switch ($scope) {
				case 'providers':
					$resultTotals['resultCountProviders'] = count($results['providers']);
					$resultTotal += $resultTotals['resultCountProviders'];
					break;
				case 'employees':
					$resultTotals['resultCountEmployees'] = count($results['employees']);
					$resultTotal += $resultTotals['resultCountEmployees'];
					break;
				case 'businesses':
					$resultTotals['resultCountBusinesses'] = count($results['businesses']);
					$resultTotal += $resultTotals['resultCountBusinesses'];
					break;
			}
		}
		
		if (!$this->returnResult) {
			// Only save search results if called externally
			$scopes = implode(',', $data['scope']);
			$this->saveSearch(
				$data, 
				$data['search-phrase'], 
				$scopes, 
				array(
					'resultCountTotal' => $resultTotal, 
					'resultCountProviders' => $resultTotals['resultCountProviders'], 
					'resultCountEmployees' => $resultTotals['resultCountEmployees'], 
					'resultCountBusinesses' => $resultTotals['resultCountBusinesses']
				)
			);
		}
		
		return $this->respond($results);
	}
	
	/**
	 * Returns providers based on a search phrase.
	 * 
	 * The following fields are searched: 'providerNPI', 'employeeID', 'lastName', 'firstName', 'specialty1', 'specialty2', 'specialty3'.
	 * 
	 * Method: POST
	 *
	 * Request format:
	 * `/api/search-providers`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "search-phrase": "b Barker"
	 *     "max-results": 20 (optional. default is 20)
	 *     "response-fields": [an array of fields to respond with] (optional)
	 * }
	 * ```
	 * 
	 * Example response with all fields:
	 * ```
	 * [
	 *     {
	 *         "providerNPI": "1467503920",
	 *         "lastName": "Kim",
	 *         "firstName": "Jin",
	 *         "middleName": "Koo",
	 *         "suffix": "",
	 *         "specialty1": "Nephrology",
	 *         "specialty2": "",
	 *         "specialty3": "",
	 *         "cellPhoneNumber": "(928) 499-2157",
	 *         "primaryEmail": "jkim@akdhc.com; credentialing@akdhc.com",
	 *         "sex": "F"
	 *     },
	 *     ...
	 * ]
	 * ```
	 * 
	 * @var $data array
	 * 
	 * @return array
	 * @throws Exception
	 */
	public function searchProviders($data = null) {
		if ($data === null) {
			$data = $this->getPostData();
			if ($data === null) {
				$error = json_last_error_msg();
				return $this->respondError("Invalid JSON in POST data. $error", 400);
			}
		}
		
		if (empty($data['search-phrase'])) {
			return $this->respondError("Missing 'search-phrase' in the request data.", 400);
		}
		
		$terms = $this->getSearchTerms($data['search-phrase']);
		if (count($terms) == 0) {
			return $this->respond(array());
		}
		
		$em = $this->getEntityManager();
		
		// Validate response-fields
		$responseFields = array('*');
		if (!empty($data['response-fields']) && is_array($data['response-fields'])) {
			$responseFields = $data['response-fields'];
		}
		
		if ($responseFields !== array('*')) {
			$fields = $em->getClassMetadata('Provider')->getFieldNames();
			foreach ($data['response-fields'] as $rf) {
				if (!in_array($rf, $fields)) {
					return $this->respondError("Invalid value '$rf' in response-fields for providers.");
				}
			}
		}
		
		$ws = new WeightedSearch();
		
		$maxResults = $this::DEFAULT_MAX_SEARCH_RESULTS;
		if (!empty($data['max-results']) && (!is_numeric($data['max-results']) || $data['max-results'] < 1)) {
			return $this->respondError("'max-results' should be numeric and greater than 1.");
		}
		
		if (!empty($data['max-results'])) {
			$maxResults = $data['max-results'];
		}
		$ws->setLimit($maxResults);
		
		$results = $ws->search(
			$em, $terms, 'providers', 
			array(
				'providerNPI',
				'employeeID',
				'lastName', 
				'firstName', 
				'specialty1', 
				'specialty2', 
				'specialty3'
			), 
			'lastName ASC', $responseFields, 'provider', 'providerNPI'
		);
		
		if (!$this->returnResult) {
			// Only save search results if called externally
			$this->saveSearch($data, $data['search-phrase'], 'providers', array('resultCountTotal' => count($results), 'resultCountProviders' => count($results)));
		}
		
		return $this->respond($results);
	}
	
	/**
	 * Returns businesses based on a search phrase.
	 * 
	 * The following fields are searched: 'businessID', 'displayName', 'name', 'costCenter', 'midasID'.
	 *
	 * Method: POST
	 *
	 * Request format:
	 * `/api/search-businesses`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "search-phrase": "b Barker"
	 *     "max-results": 20 (optional. default is 20)
	 *     "response-fields": [an array of fields to respond with] (optional),
	 *     "include-inactive": false (optional, default is false),
	 *     "only-inactive": false (optional, default is false, overrides include-inactive),
	 *     "only-parents": false (optional, default it false, set to true to only return parent businesses)
	 * }
	 * ```
	 *
	 * Example response with all fields:
	 * ```
	 * [
	 *     {
	 *         "businessID": "784",
	 *         "parentBusinessID": "779",
	 *         "costCenter": "",
	 *         "processLevel": "",
	 *         "propertyReportsTo": "",
	 *         "propertyLocation": "FMC",
	 *         "type": "Building",
	 *         "name": "FVSC - Education",
	 *         "directorID": "",
	 *         "vpID": "",
	 *         "property": "FLAGSTAFF",
	 *         "directions": "",
	 *         "intranetURL": "",
	 *         "webURL": "",
	 *         "hours": "",
	 *         "promoLine": "",
	 *         "created": "2014-03-31 15:14:15.0",
	 *         "lastUpdated": "2014-03-31 15:14:15.0",
	 *         "isBlind": "0",
	 *         "isActive": "1",
	 *         "publicWebsite": "No",
	 *         "employeePortal": "No",
	 *         "midasID": "",
	 *         "source": ""
	 *     },
	 *     ...
	 * ]
	 * ```
	 *
	 * @var $data array
	 *
	 * @return array
	 * @throws Exception
	 */
	public function searchBusinesses($data = null) {
		if ($data === null) {
			$data = $this->getPostData();
			if ($data === null) {
				$error = json_last_error_msg();
				return $this->respondError("Invalid JSON in POST data. $error", 400);
			}
		}
		
		if (empty($data['search-phrase'])) {
			return $this->respondError("Missing 'search-phrase' in the request data.", 400);
		}
		
		$terms = $this->getSearchTerms($data['search-phrase']);
		if (count($terms) == 0) {
			return $this->respond(array());
		}
		
		$em = $this->getEntityManager();
		
		// Validate response-fields
		$responseFields = array('*');
		if (!empty($data['response-fields']) && is_array($data['response-fields'])) {
			$responseFields = $data['response-fields'];
		}
		
		if ($responseFields !== array('*')) {
			$fields = $em->getClassMetadata('Business')->getFieldNames();
			foreach ($responseFields as $rf) {
				if (!in_array($rf, $fields)) {
					return $this->respondError("Invalid value '$rf' in response-fields for businesses.");
				}
			}
		}
		
		$ws = new WeightedSearch();
		
		$maxResults = $this::DEFAULT_MAX_SEARCH_RESULTS;
		if (!empty($data['max-results']) && (!is_numeric($data['max-results']) || $data['max-results'] < 1)) {
			return $this->respondError("'max-results' should be numeric and greater than 1.");
		}
		
		if (!empty($data['max-results'])) {
			$maxResults = $data['max-results'];
		}
		$ws->setLimit($maxResults);
		
		$includeInactive = false;
		if (!empty($data['include-inactive'])) {
			$includeInactive = $data['include-inactive'];
		}
		
		$onlyInactive = false;
		if (!empty($data['only-inactive'])) {
			$onlyInactive = $data['only-inactive'];
		}
		
		if ($onlyInactive) {
			$ws->setAndWhere("isActive=false");
		}
		else if (!$includeInactive) {
			$ws->setAndWhere("isActive=true");
		}
		
		if (!empty($data['only-parents']) && $data['only-parents'] == true) {
			$ws->setAndWhere('parentBusinessID=0');
		}
		
		$results = $ws->search(
			$em, $terms, 'businesses', 
			array('displayName', 'businessID', 'costCenter', 'midasID'), 
			'name ASC', $responseFields, 'business', 'businessID'
		);
		
		if (!$this->returnResult) {
			// Only save search results if called externally
			$this->saveSearch($data, $data['search-phrase'], 'businesses', array('resultCountTotal' => count($results), 'resultCountBusinesses' => count($results)));
		}
		
		return $this->respond($results);
	}
	
	/**
	 * Returns employees based on a search phrase.
	 * 
	 * The following fields are searched: 'employeeID', 'userName', 'lastName', 'firstName', 'title'.
	 *
	 * Method: POST
	 *
	 * Request format:
	 * `/api/search-businesses`
	 *
	 * POST data (required):
	 * ```
	 * {
	 *     "search-phrase": "b Barker"
	 *     "max-results": 20 (optional. default is 20)
	 *     "response-fields": [an array of fields to respond with] (optional),
	 *     "include-inactive": false (optional, default is false),
	 *     "only-inactive": false (optional, default is false, overrides include-inactive)
	 * }
	 * ```
	 *
	 * Example response with all fields:
	 * ```
	 * [
	 *     {
	 *         "employeeID": "35243",
	 *         "lastName": "KIM",
	 *         "firstName": "HEE WON",
	 *         "email": "kim@nahhealth.com",
	 *         "userName": "HK35243",
	 *         "positionCode": "PHYS829635E",
	 *         "title": "PHYSICIAN",
	 *         "propertyID": "80",
	 *         "property": "FLAGSTAFF",
	 *         "costCenter": "29635",
	 *         "hireDate": "2015-04-13",
	 *         "terminationDate": null,
	 *         "birthDate": "4\/10",
	 *         "supervisorEmployeeID": "17926",
	 *         "directorEmployeeID": "27345",
	 *         "vpEmployeeId": "29176"
	 *     },
	 *     ...
	 * ]
	 * ```
	 *
	 * @var $data array
	 *
	 * @return array
	 * @throws Exception
	 */
	public function searchEmployees($data = null) {
		if ($data === null) {
			$data = $this->getPostData();
			if ($data === null) {
				$error = json_last_error_msg();
				return $this->respondError("Invalid JSON in POST data. $error", 400);
			}
		}
		
		if (empty($data['search-phrase'])) {
			return $this->respondError("Missing 'search-phrase' in the request data.", 400);
		}
		
		$terms = $this->getSearchTerms($data['search-phrase']);
		if (count($terms) == 0) {
			return $this->respond(array());
		}
		
		$em = $this->getEntityManager();
		
		// Validate response-fields
		$responseFields = array('*');
		if (!empty($data['response-fields']) && is_array($data['response-fields'])) {
			$responseFields = $data['response-fields'];
		}
		
		if ($responseFields !== array('*')) {
			$fields = $em->getClassMetadata('Employee')->getFieldNames();
			foreach ($responseFields as $rf) {
				if (!in_array($rf, $fields)) {
					return $this->respondError("Invalid value '$rf' in response-fields for employees.");
				}
			}
		}
		
		$ws = new WeightedSearch();
		
		$maxResults = $this::DEFAULT_MAX_SEARCH_RESULTS;
		if (!empty($data['max-results']) && (!is_numeric($data['max-results']) || $data['max-results'] < 1)) {
			return $this->respondError("'max-results' should be numeric and greater than 1.");
		}
		
		if (!empty($data['max-results'])) {
			$maxResults = $data['max-results'];
		}
		$ws->setLimit($maxResults);
		
		$includeInactive = false;
		if (!empty($data['include-inactive'])) {
			$includeInactive = $data['include-inactive'];
		}
		
		$onlyInactive = false;
		if (!empty($data['only-inactive'])) {
			$onlyInactive = $data['only-inactive'];
		}
		
		if ($onlyInactive) {
			$ws->setAndWhere("terminationDate <= NOW()");
		}
		else if (!$includeInactive) {
			$ws->setAndWhere("terminationDate IS NULL OR terminationDate > NOW()");
		}
		
		// Add isActive field
		$ws->addSelect('IF(terminationDate IS NULL OR terminationDate > NOW(), true, false) AS isActive');
		
		$results = $ws->search(
			$em, $terms, 'employees', 
			array(
				'employeeID',
				'userName',
				'lastName', 
				'firstName'), 
			'lastName ASC', $responseFields, 'employee', 'employeeID'
		);
		
		if (!$this->returnResult) {
			// Only save search results if called externally
			$this->saveSearch($data, $data['search-phrase'], 'employees', array('resultCountTotal' => count($results), 'resultCountEmployees' => count($results)));
		}
		
		return $this->respond($results);
	}
	
	/**
	 * Helper function that breaks up a search phrase into an array of terms.
	 * The phrase itself is also one of the terms in the array.
	 * 
	 * @param $phrase
	 *
	 * @return array
	 */
	private function getSearchTerms($phrase) {
		// Discard anything after a comma in the phrase
		$commaPos = stripos($phrase, ',');
		if ($commaPos !== false) {
			$phrase = substr($phrase, 0, $commaPos);
		}
		
		// Replace all periods with a space
		$phrase = str_replace('.', ' ', $phrase);
		
		$terms = explode(' ', $phrase);
		
		// Add the entire phrase as a term
		if (!in_array($phrase, $terms)) {
			array_unshift($terms, $phrase);
		}
		
		// Sanitize
		$cleanTerms = array();
		foreach ($terms as $term) {
			$term = strtolower(trim($term));
			if (!empty($term)) {
				$cleanTerms[] = $term;
			}
		}
		
		// Abbreviations
		foreach (self::$searchAbbreviations as $abbrv => $wholeWord) {
			if (in_array($abbrv, $cleanTerms)) {
				$cleanTerms[] = $wholeWord;
			}
			else if (in_array($wholeWord, $cleanTerms)) {
				$cleanTerms[] = $abbrv;
			}
		}
		
		return $cleanTerms;
	}
	
	/**
	 * Helper function for saving a search query.
	 *
	 * @param $requestData array
	 * @param $phrase string
	 * @param $scope string 
	 * @param array $totals must have 'resultCountTotal'. Optionally: 'resultCountProviders', 'resultCountEmployees', 'resultCountBusinesses'
	 * @param $date DateTime Defaults to "now"
	 */
	private function saveSearch($requestData, $phrase, $scope, $totals, $date = null) {
		if ($date === null) {
			$date = new DateTime();
		}
		
		$query = new SearchQuery();
		$query->setRequestData($requestData);
		$query->setDate($date);
		$query->setPhrase($phrase);
		$query->setScope($scope);
		foreach ($totals as $type => $count) {
			$query->setTotal($type, $count);
		}
		
		$em = $this->getEntityManager();
		$em->persist($query);
		$em->flush();
	}
	
	/**
	 * Helper function to get data from a POST.
	 * 
	 * @return array|null
	 */
	private function getPostData() {
		$data = null;
		
		// Get from the POST global first
		if (empty($_POST)) {
			// For API calls we need to look at php://input
			if (!empty(file_get_contents('php://input'))) {
				$data = @json_decode(file_get_contents('php://input'), true);
				if ($data === false || $data === null) {
					return null;
				}
			}
			else {
				//TODO: What is this for? isn't 'php://input' always empty at this point?
				$dataStr = file_get_contents('php://input');
				parse_str($dataStr, $data);
			}
		}
		else {
			$data = $_POST;
		}
		
		// Normalize boolean values
		foreach ($data as $name => &$value) {
			if ($value === 'true') {
				$value = true;
			}
			else if ($value === 'false') {
				$value = false;
			}
		}
		
		return $data;
	}
}