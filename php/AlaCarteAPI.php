<?php

use \Gozer\Core\CoreAPI;
use \Gozer\Core\CoreAPIResponseJSON;
use Doctrine\ODM\MongoDB\DocumentManager;
use MongoDocs\Node;
use MongoDocs\Trigger;
use MongoDocs\Content;
use MongoDocs\Banner;
use MongoDocs\User;

/**
 * Class AlaCarteAPI
 */
class AlaCarteAPI extends CoreAPI {
	
	/** @var $dm DocumentManager */
	private $dm = null;

	/**
	 * Constructor
	 */
	public function __construct($bypassAuth = false) {
		try {
			$this->setResponder(new CoreAPIResponseJSON());
			parent::__construct(array('login', 'getffaddon'), $bypassAuth);
			$this->dm = AlaCarteDB::connect();
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Use this to obtain an access token for use in subsequent calls.
	 * All other function of this API need to have the access token in 
	 * the query string when calling:
	 * `http://alacarte.bluetree.ws/api/get/content?access_token=[token]`
	 * 
	 * Example request:
	 * ```
	 * curl -i -X POST -H "Content-Type:application/x-www-form-urlencoded" -d 'grant_type=client_credentials&client_id=[client id]&client_secret=[client secret]' 'http://alacarte.bluetree.ws/api/authorize'
	 * ```
	 * 
	 * Parameters:
	 * - grant_type: Always use `client_credentials`
	 * - client_id: Client id
	 * - client_secret: Password
	 * 
	 * Example response:
	 * ```
	 * {
	 *   "access_token":"b76697b07b1ad0214ce06833cdf17e9ed4436053",
	 *   "expires_in":3600,
	 *   "token_type":"Bearer",
	 *   "scope":null
	 * }
	 * ```
	 * 
	 * If the access token has expired call this method again to get a new one.
	 */
	public function getOAuth2Token() {
		parent::getOAuth2Token();
	}

	/**
	 * Echos the API docs.
	 */
	public function docs() {
		echo(file_get_contents(BASE_PATH . '/app/views/docs/index.html'));
		exit();
	}

	/**
	 * Returns the highest version of the Firefox add-on available.
	 */
	public function checkFFNewVersion() {
		try {
			$files = @scandir(BASE_PATH . '/public/ff_addon');
			if ($files === false ) {
				throw new Exception("Can't read directory " . BASE_PATH . '/public/ff_addon');
			}
			
			$highestVersion = array('version' => '0', 'file' => '');
			foreach ($files as $file) {
				if (is_file(BASE_PATH . '/public/ff_addon/' . $file)) {
					$fileName = pathinfo($file, PATHINFO_FILENAME);
					
					$parts = explode('-', $fileName);
					if (isset($parts[1])) {
						$version = $parts[1];
						if (version_compare($version, $highestVersion['version'], '>')) {
							$highestVersion['version'] = $version;
							$highestVersion['file'] = $file;
						}
					}
				}
			}

			$this->respond(array(
				"version" => $highestVersion['version'],
				"url" => BASE_URL . "/ff_addon/" . $highestVersion['file']
			));
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Adds a new Content document to the Node. If no node with the 
	 * given alacateId exists, it will be created.
	 * 
	 * @param $alacarteId string the alacarte-id of the Node to add content to.
	 * @param $date DateTime
	 */
	public function addContent($alacarteId, $date = null) {
		try {
			$data = json_decode(file_get_contents('php://input'), true);

			// Look for an existing Node
			$node = $this->dm->getRepository('MongoDocs\Node')->findOneByAlacarteId($alacarteId);
			if ($node === null) {
				$node = new Node();
				$node->setAlacarteId($alacarteId);
				$node->setContentType($data['contentType']);
				$node->setPath(strtolower($data['path']));
				$node->setGroupName($data['groupName']);
			}
			else {
				$node->setPath(strtolower($data['path']));
			}

			$content = new Content();
			
			if (isset($data['userId'])) {
				$user = $this->dm->getRepository('MongoDocs\User')->findOneById($data['userId']);
				if ($user != null) {
					$content->setUser($user);
				}
			}
			
			if (isset($data['content'])) {
				$content->setContent($data['content']);
			}
			
			if ($date !== null) {
				$content->setDate($date);
			}
			else {
				$content->setDate(new DateTime());
			}
			
			if (isset($data['status'])) {
				$content->setStatus($data['status']);
			}
			else {
				// Default status for new content
				$content->setStatus('staged');
			}
			
			if (isset($data['matchAllTriggers'])) {
				$content->setMatchAllTriggers($data['matchAllTriggers']);
			}
			else {
				$content->setMatchAllTriggers(false);
			}

			if (isset($data['notEqualTo'])) {
				$content->setNotEqualTo($data['notEqualTo']);
			}
			else {
				$content->setNotEqualTo(false);
			}

			if (isset($data['global'])) {
				$content->setGlobal($data['global']);
			}
			else {
				$content->setGlobal(false);
			}
			
			$content->setHidden($data['hidden']);
			if ($node->getContentType() == 'link') {
				$content->setUrl($data['url']);
			}
			else if ($node->getContentType() == 'orbit-banner') {
				$content->setUrl($data['url']);
				$content->setImageUrl($data['imageUrl']);
			}

			foreach ($data['triggers'] as $t) {
				$trigger = new Trigger();
				$trigger->setType($t['type']);
				$trigger->setValue($t['value']);
				$content->addTrigger($trigger);
			}

			$node->addContent($content);

			$this->dm->persist($node);
			$this->dm->flush();

			$this->respond(array(
				"success" => true,
				"nodeId" => $node->getId(),
				"contentId" => $content->getId()
			));
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Replaces the existing Content document. A new content id is returned.
	 * 
	 * @param $contentId string The mongo id of the Content document.
	 */
	public function saveContent($contentId) {
		try {
			if ($contentId == null || $contentId == false || $contentId == "undefined" || $contentId == "false") {
				$this->respondError("Invalid content ID.");
			}
			
			$data = json_decode(file_get_contents('php://input'), true);

			$node = $this->dm->getRepository('MongoDocs\Node')->findOneByAlacarteId($data['alacarteId']);
			if ($node !== null) {
				$content = $this->dm->getRepository('MongoDocs\Content')->findOneById($contentId);
				if ($content == null) {
					$this->respondError("Content with id " . $contentId . " not found.");
				}
				
				foreach ($content->getTriggers() as $trigger) {
					$this->dm->remove($trigger);
				}
				$node->removeContent($content);
				$this->dm->remove($content);
				$this->dm->persist($node);
				
				$this->addContent($data['alacarteId'], $content->getDate());
			}
			else {
				$this->respondError("Node with alacaarte_id {$data['alacarteId']} not found.");
			}
			//$this->respond("{'success':true}");
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Delete content from a node.
	 * 
	 * @param $contentId
	 */
	public function deleteContent($contentId) {
		try {
			$content = $this->dm->getRepository('MongoDocs\Content')->findOneById($contentId);
			if ($content != null) {
				$node = $this->dm->getRepository('MongoDocs\Node')->findOneByContent($content->getId());
				$node->removeContent($content);
				foreach ($content->getTriggers() as $trigger) {
					$this->dm->remove($trigger);
				}
				$this->dm->remove($content);

				// Delete the node if it has no more content
				if (count($node->getContent()) == 0) {
					$this->dm->remove($node);
				}
				else {
					$this->dm->persist($node);
				}

				$this->dm->flush();
			}
			else {
				$this->respondError("Content with id $contentId not found.");
			}
			$this->respond("{'success':true}");
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}
	
	/**
	 * Uploads a banner file.
	 */
	public function uploadBannerFile() {
		try {
			$data = json_decode(file_get_contents('php://input'), true);

			// TODO: Organize files in sub-dirs by group name.
			
			$file = base64_decode($data['file']);
			// TODO: If the directory doesn't exist create it.
			$success = @file_put_contents(BASE_PATH . '/public/banners/' . $data['name'], $file);
			if ($success === false) {
				throw new Exception("Could not write to file " . BASE_PATH . '/public/banners/' . $data['name']);
			}
			
			$user = $this->getUserFromAccessToken($_GET['access_token']);
			if ($user == null) {
				throw new Exception("Invalid access token.");
			}
			
			$group = '';
			if ($user->getGroup() !== null) {
				$group = $user->getGroup()->getMachineName() . '/';
			}

			$response = array(
				'url' => BASE_URL . '/banners/' . $group . rawurlencode($data['name'])
			);

			$this->respond($response);
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Returns a single node with the given alacarte-id. The content is ordered by the date they were created.
	 * 
	 * @param $alacarteId
	 */
	public function getNode($alacarteId) {
		try {
			// TODO: Validate the access_token to make sure the currently authenticated user/group has access to the requested node.
			
			$node = $this->dm->getRepository('MongoDocs\Node')->findOneByAlacarteId($alacarteId);
			if ($node != null) {
				$nodeArray = $node->toArray();

				// Sort content by date
				usort($nodeArray['content'], function ($a, $b) {
					if ($a['date'] == $b['date']) {
						return 0;
					}

					return ($a['date'] < $b['date']) ? -1 : 1;
				});

				$this->respond($nodeArray);
			}
			else {
				$this->respond(null);
			}
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Deletes a node and all it's content changes.
	 * 
	 * @param $alacarteId
	 */
	public function deleteNode($alacarteId) {
		try {
			$node = $this->dm->getRepository('MongoDocs\Node')->findOneByAlacarteId($alacarteId);
			if ($node !== null) {
				$this->dm->remove($node);
				//$this->dm->persist($node);
				$this->dm->flush();
			}
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Respond with an array of nodes with current content that match the input.
	 * By default this will only consider active content. To change this pass "status":"[live|staged|disabled]".
	 * 
	 * GET | POST
	 * 
	 * Parameters;
	 *   - *group_name*: The client group name.
	 *   - *path*: The complete URL path after the domain.
	 *   - *triggers*: (Optional) An array of triggers with 'type' and 'value'.
	 *   - *content_type*: (Optional) The content type or an array of content types such as 'link'.
	 *   - *status*: (Optional) The content status or an array of status to return. 
	 *     Statuses are 'live', 'staged', and 'disabled'. If not set only 'live' content is returned.
	 * 
	 * Example request:
	 * 
	 * `curl -i -X POST -H "Content-Type:application/json" -d '{"group_name":"allied","path":"/travel","triggers":[{"type":"sub_domain","value":"test.ohiovalley"}]}' 'http://dev.alacarte.bluetree.ws/api/nodes/get/content'`
	 * 
	 * or
	 * 
	 * `curl -i -X GET -H "Content-Type:application/json" 'http://local.alacarte.com/api/nodes/get/content?data=\{"group_name":"allied","path":"/travel","triggers":\[\{"type":"sub_domain","value":"test.ohiovalley"\}\]\}'`
	 * 
	 * data:
	 * ```
	 * {
	 *   "group_name":"allied",
	 *   "path":"/travel",
	 *   "triggers":
	 *   [
	 *     {"type":"sub_domain","value":"test.ohiovalley"}
	 *   ],
	 *   "content_type": [
	 *     "link", "block", "text"
	 *   ],
	 *   "status": [
	 *     "live","staged"
	 *   ]
	 * }
	 * ```
	 * 
	 * Responds with a json array of nodes with the following properties:
	 *   - *alacarte_id*: [the id set by the client site],
	 *   - *content_type*: <"text"|"link"|"block">
	 *   - *active_content*: [the currently active content]
	 * 
	 * Example response:
	 * ```
	 *    [
	 *      {
	 *        "alacarte_id": "94e5e436e7c68ae76ca7e657",
	 *        "content_type": "text",
	 *        "content": {
	 *          id": "555cac1f4475f03e2e1b1f99",
	 *          "hidden": false,
	 *          "text": "This is a text content."
	 *        }
	 *      },
	 *      {
	 *        "alacarte_id": "4a3c1b4141a41b4c1a4c1a1b",
	 *        "content_type": "link",
	 *        "content": {
	 *          id": "555c967f4475f0002e1b1f94",
	 *          "hidden": false,
	 *          "link": {
	 *            "url": "http:\/\/ohio.com",
	 *            "text": "This is Ohio State!"
	 *          }
	 *        }
	 *      },
	 *      {
	 *        "alacarte_id": "6abe777ba6e7aea57abe23e",
	 *        "content_type": "block",
	 *        "content": {
	 *          id": "555c967ff6e420002e1b1f95",
	 *          "hidden": true
	 *        }
	 *      }
	 *    ]
	 * ```
	 * 
	 * @see Node::CONTENT_TYPES
	 */
	public function getContent() {
		try {
			if (empty($_GET['data'])) {
				$input = file_get_contents('php://input');
				if (empty($input)) {
					$this->respondError("Missing input parameters.");

					return;
				}
			}
			else {
				$input = $_GET['data'];
			}

			$input = json_decode($input, true);
			if ($input == null || $input == false) {
				$this->respondError("Invalid input. Could not decode JSON.");

				return;
			}
			
			$triggers = array();
			if (isset($input['triggers'])) {
				$triggers = $input['triggers'];
			}

			$path = empty($input['path']) ? null : $input['path'];
			$groupName = empty($input['group_name']) ? null : $input['group_name'];
			$contentType = empty($input['content_type']) ? null : $input['content_type'];
			$ignoreNotEqualTo = empty($input['ignore_not_equal_to']) ? false : $input['ignore_not_equal_to'];

			$banners = array();
			$includeBanners = false;
			if ($contentType === null || (is_array($contentType) && in_array('orbit-banner', $contentType)) || $contentType == 'orbit-banner') {
				$includeBanners = true;
			}

			if (!empty($path)) {
				if (!empty($groupName)) {
					// Both path and group_name
					$qb = $this->dm->createQueryBuilder('MongoDocs\Node')
						->field('groupName')->equals($groupName);

					if (is_array($path)) {
						foreach ($path as $p) {
							// Case-insensitive compare
							$pathReg = "/^" . preg_quote($p, '/') . "$/i";
							$qb->addOr($qb->expr()->field('path')->equals(new \MongoRegex($pathReg)));
						}
					}
					else {
						$pathReg = "/^" . preg_quote($path, '/') . "$/i";
						$qb->addOr($qb->expr()->field('path')->equals(new \MongoRegex($pathReg)));
					}
					
					if (is_array($contentType)) {
						$qb->field('contentType')->in($contentType);
					}
					else if ($contentType != null) {
						$qb->field('contentType')->equals($contentType);
					}

					// Include nodes with global content
					$qb3 = $this->dm->createQueryBuilder('MongoDocs\Content')->field('global')->equals(true);
					$query = $qb3->getQuery();
					$contents = $query->execute();
					foreach ($contents as $content) {
						$qb->addOr($qb->expr()->field('content')->references($content));
					}
					
					$query = $qb->getQuery();
					$nodes = $query->execute();
					
					// Banners
					if ($includeBanners) {
						$qb2 = $this->dm->createQueryBuilder('MongoDocs\Banner')->field('groupName')->equals($groupName);

						if (is_array($path)) {
							foreach ($path as $p) {
								// Case-insensitive compare
								$pathReg = "/^" . preg_quote($p, '/') . "$/i";
								$qb2->addOr($qb->expr()->field('path')->equals(new \MongoRegex($pathReg)));
							}
						}
						else {
							$pathReg = "/^" . preg_quote($path, '/') . "$/i";
							$qb2->field('path')->equals(new \MongoRegex($pathReg));
						}

						$query2 = $qb2->getQuery();
						$banners = $query2->execute();
					}
				}
			// TODO: Need to update the reset of these conditions to reflect changes in options (content_type etc.)
				else {
					// Just path
					$pathReg = "/^" . preg_quote($path, '/') . "$/i";
					$nodes = $this->dm->getRepository('MongoDocs\Node')->findByPath(new \MongoRegex($pathReg));
					$banners = $this->dm->getRepository('MongoDocs\Banner')->findByPath(new \MongoRegex($pathReg));
				}
			}
			else {
				if (!empty($groupName)) {
					// Just group_name
					$nodes = $this->dm->getRepository('MongoDocs\Node')->findByGroupName($groupName);
					$banners = $this->dm->getRepository('MongoDocs\Banner')->findByGroupName($groupName);
				}
				else {
					// Neither
					// TODO: Maybe shouldn't allow this because it will return content across all groups.
					$nodes = $this->dm->getRepository('MongoDocs\Node')->findAll();
					$banners = $this->dm->getRepository('MongoDocs\Banner')->findAll();
				}
			}
			
			$response = array();
			$contentBanners = array();
			foreach ($nodes as $index => $node) {
				$status = 'live';
				if (isset($input['status'])) {
					$status = $input['status'];
				}
				$content = $node->matchContent($triggers, $status, $ignoreNotEqualTo);
				if ($content !== null) {
					$sort = 0;
					if ($node->getContentType() == 'orbit-banner') {
						foreach ($banners as $banner) {
							if ($banner->getAlacarteId() == $node->getAlacarteId()) {
								$sort = $banner->getId();
							}
						}
					}
					$response[] = array(
						'sort' => $sort,
						'id' => $node->getId(),
						'alacarte_id' => $node->getAlacarteId(),
						'content_type' => $node->getContentType(),
						'content' => $content
					);

					if ($includeBanners) {
						if ($node->getContentType() == 'orbit-banner') {
							$contentBanners[$index] = $node->getAlacarteId();
						}
					}
				}
			}

			// Add banners that have no content because we want to return all banners
			if ($includeBanners) {
				foreach ($banners as $banner) {
					$acId = $banner->getAlacarteId();
					if (!in_array($acId, $contentBanners)) {
						$response[] = array(
							'sort' => $banner->getId(),
							'id' => null,
							'alacarte_id' => $banner->getAlacarteId(),
							'content_type' => 'orbit-banner',
							'content' => array(
								'hidden' => false,
								'image_url' => $banner->getImageUrl(),
								'link_url' => $banner->getLinkUrl()
							)
						);
					}
				}

				// Order the banners by id which should be the order they appear on the page.
				$ids = array();
				foreach ($response as $key => $row) {
					if (isset($row['sort'])) {
						$ids[$key] = $row['sort'];
					}
					else {
						$ids[$key] = 0;
					}
				}

				array_multisort($ids, SORT_ASC, $response);
			}
			
			$this->respond($response);
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Returns all trigger definitions for the authenticated group.
	 */
	public function getTriggerDefs($group) {
		try {
			if (!empty($_REQUEST['access_token'])) {
				$group = $this->dm->getRepository('MongoDocs\Group')->findOneByMachineName($group);
				
				if ($group == null) {
					$this->respondError('Invalid group machine name.');
					return;
				}
				
				$qb = $this->dm->createQueryBuilder('MongoDocs\TriggerDef')
					->field('group')->references($group)
					->sort('name');
				$triggerDefs = $qb->getQuery()->execute();

				$this->respond($triggerDefs->toArray());
			}
			else {
				$this->respondError('Missing access token.', 401);
			}
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}

	/**
	 * Checks credentials and returns an AlaCarte user.
	 * 
	 * Example request:
	 * {
	 *  "email": "john@doe.com",
	 *  "password": "mypassword"
	 * }
	 */
	public function login() {
		try {
			$data = json_decode(file_get_contents('php://input'), true);
			
			if (!isset($data['email']) || !isset($data['password'])) {
				$this->respondError('Missing input', 400);
				return;
			}
			
			$user = $this->dm->getRepository('MongoDocs\User')->findOneByEmail($data['email']);
			if ($user == null || (!$user->verifyPassword($data['password']) && !$user->verifyTempPassword($data['password']))) {
				$this->respondError('Email not found or invalid password', 401);
				return;
			}
			
			$response = array('error' => false, 'user' => $user);
			
			if ($user->getPassword() == null) {
				$response['temp_pw'] = true;
			}
			
			$this->respond($response);
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}
	
	/**
	 * Set the password for the currently authenticated user (based on the access token).
	 */
	public function setPassword() {
		try {
			$data = json_decode(file_get_contents('php://input'), true);
			
			if (!isset($data['password'])) {
				$this->respondError('Missing input', 400);
				return;
			}
			
			$user = $this->getUserFromAccessToken($_GET['access_token']);
			if ($user == null) {
				throw new Exception("Invalid access token.");
			}
			
			$user->setPassword($data['password']);
			$this->dm->persist($user);
			$this->dm->flush();
		}
		catch (Exception $e) {
			$this->respondError($e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\n" . $e->getTraceAsString());
		}
	}
	
	/**
	 * Returns the group for the provided domain.
	 * 
	 * Example request:
	 * {
	 *  "domain":"test.ohiovalley.aaa.com"
	 * }
	 */
	public function getGroupFromDomain() {
		$data = json_decode(file_get_contents('php://input'), true);
		if (empty($data['domain'])) {
			$this->respondError('Missing input', 400);
			return;
		}
		
		$qb = $this->dm->createQueryBuilder('MongoDocs\Group')
			->field('domains')->equals($data['domain']);
		$group = $qb->getQuery()->getSingleResult();
		
		if ($group !== null) {
			$response = array("group" => $group->toArray());
			$this->respond($response);
		}
		else {
			$this->respondError("No group found for the domain {$data['domain']}");
		}
	}

	/**
	 * Helper function for adding a client login to OAuth.
	 * 
	 * @param $id
	 * @param $secret
	 */
	public function addOAuthUser($id, $secret) {
		$storage = $this->oauthServer->getStorage('client_credentials');
		$storage->setClientDetails($id, $secret, '');
	}
	
	/**
	 * Helper function that finds a user given an access token.
	 * 
	 * @param $token
	 *
	 * @return mixed
	 */
	private function getUserFromAccessToken($token) {
		$clientId = $this->getClientIdFromAccessToken($token);
		$user = $this->dm->getRepository('MongoDocs\User')->findOneByApiClientId($clientId);
		return $user;
	}
}