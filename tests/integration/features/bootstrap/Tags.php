<?php
/**
 * @author Lukas Reschke <lukas@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

require __DIR__ . '/../../../../lib/composer/autoload.php';

use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\Client;
use GuzzleHttp\Message\ResponseInterface;

trait Tags {

	/** @var array */
	private $createdTags = array();

	/**
	 * @param string $user
	 * @param string $type
	 * @param string $name
	 * @param string $groups
	 */
	private function createTag($user, $userVisible, $userAssignable, $name, $groups = null) {
		$tagsPath = '/systemtags/';
		$body = [
			'name' => $name,
			'userVisible' => $userVisible,
			'userAssignable' => $userAssignable,
		];
		if ($groups !== null) {
			$body['groups'] = $groups;
		}
		try {
			$this->response = $this->makeDavRequest($user,
								  "POST",
								  $tagsPath,
								  ['Content-Type' => 'application/json',],
								  null,
								  "uploads",
								  json_encode($body));
			$responseHeaders =  $this->response->getHeaders();
			$tagUrl = $responseHeaders['Content-Location'][0];
			$lastTagId = substr($tagUrl, strrpos($tagUrl,'/')+1);
			array_push($this->createdTags, $lastTagId);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	private function validateTypeOfTag($type) {
		$userVisible = true;
		$userAssignable = true;
		switch ($type) {
			case 'normal':
				break;
			case 'not user-assignable':
				$userAssignable = false;
				break;
			case 'not user-visible':
				$userVisible = false;
				break;
			default:
				throw new \Exception('Unsupported type');
		}
		return array($userVisible, $userAssignable);
	}

	/**
	 * @When :user creates a :type tag with name :name
	 * @param string $user
	 * @param string $type
	 * @param string $name
	 * @throws \Exception
	 */
	public function createsATagWithName($user, $type, $name) {
		$this->createTag($user, $this->validateTypeOfTag($type)[0], $this->validateTypeOfTag($type)[1], $name);
	}



	/**
	 * @When :user creates a :type tag with name :name and groups :groups
	 * @param string $user
	 * @param string $type
	 * @param string $name
	 * @param string $groups
	 * @throws \Exception
	 */
	public function createsATagWithNameAndGroups($user, $type, $name, $groups) {
		$this->createTag($user, $this->validateTypeOfTag($type)[0], $this->validateTypeOfTag($type)[1], $name, $groups);
	}

	/**
	 * @Then The response should have a status code :statusCode
	 * @param int $statusCode
	 * @throws \Exception
	 */
	public function theResponseShouldHaveAStatusCode($statusCode) {
		if((int)$statusCode !== $this->response->getStatusCode()) {
			throw new \Exception("Expected $statusCode, got ".$this->response->getStatusCode());
		}
	}

	/**
	 * Returns all tags for a given user
	 *
	 * @param string $user
	 * @return array
	 */
	private function requestTagsForUser($user, $withGroups = false) {
		try {
			$body = '<?xml version="1.0"?>
<d:propfind  xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:id />
    <oc:display-name />
    <oc:user-visible />
	<oc:user-assignable />
	<oc:can-assign />
';

			if ($withGroups) {
				$body .= '<oc:groups />';
			}

			$body .= '
  </d:prop>
</d:propfind>';
			$request = $this->client->createRequest(
				'PROPFIND',
				$this->baseUrl . '/remote.php/dav/systemtags/',
				[
					'body' => $body,
					'auth' => [
						$user,
						$this->getPasswordForUser($user),
					],
					'headers' => [
						'Content-Type' => 'application/json',
					],
				]
			);
			$this->response = $this->client->send($request);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}

		$tags = [];
		$service = new Sabre\Xml\Service();
		$parsed = $service->parse($this->response->getBody()->getContents());
		foreach($parsed as $entry) {
			$singleEntry = $entry['value'][1]['value'][0]['value'];
			if(empty($singleEntry[0]['value'])) {
				continue;
			}

			// FIXME: use actual property names instead of guessing index position
			$tags[$singleEntry[0]['value']] = [
				'display-name' => $singleEntry[1]['value'],
				'user-visible' => $singleEntry[2]['value'],
				'user-assignable' => $singleEntry[3]['value'],
				'can-assign' => $singleEntry[4]['value'],
			];
			if (isset($singleEntry[5])) {
				$tags[$singleEntry[0]['value']]['groups'] = $singleEntry[5]['value'];
			}
		}

		return $tags;
	}

	/**
	 * @Then The following tags should exist for :user
	 * @param string $user
	 * @param TableNode $table
	 * @throws \Exception
	 */
	public function theFollowingTagsShouldExistFor($user, TableNode $table) {
		$tags = $this->requestTagsForUser($user);

		if(count($table->getRows()) !== count($tags)) {
			throw new \Exception(
				sprintf(
					"Expected %s tags, got %s.",
					count($table->getRows()),
					count($tags)
				)
			);
		}

		foreach($table->getRowsHash() as $rowDisplayName => $row) {
			foreach($tags as $key => $tag) {
				if(
					$tag['display-name'] === $rowDisplayName &&
					$tag['user-visible'] === $row[0] &&
					$tag['user-assignable'] === $row[1]
				) {
					unset($tags[$key]);
				}
			}
		}
		if(count($tags) !== 0) {
			throw new \Exception('Not expected response');
		}
	}

	/**
	 * @Then the user :user :can assign The :type tag with name :tagName
	 */
	public function theUserCanAssignTheTag($user, $can, $type, $tagName) {
		$foundTag = $this->findTag($type, $tagName, $user);
		if ($foundTag === null) {
			throw new \Exception('No matching tag found');
		}

		if ($can === 'can') {
			$expected = 'true';
		} else if ($can === 'cannot') {
			$expected = 'false';
		} else {
			throw new \Exception('Invalid condition, must be "can" or "cannot"');
		}

		if ($foundTag['can-assign'] !== $expected) {
			throw new \Exception('Tag cannot be assigned by user');
		}
	}

	/**
	 * @Then The :type tag with name :tagName has the groups :groups
	 */
	public function theTagHasGroup($type, $tagName, $groups) {
		$foundTag = $this->findTag($type, $tagName, 'admin', true);
		if ($foundTag === null) {
			throw new \Exception('No matching tag found');
		}

		if ($foundTag['groups'] !== $groups) {
			throw new \Exception('Tag has groups "' . $foundTag['group'] . '" instead of the expected "' . $groups . '"');
		}
	}

	/**
	 * @Then :count tags should exist for :user
	 * @param int $count
	 * @param string $user
	 * @throws \Exception
	 */
	public function tagsShouldExistFor($count, $user)  {
		if((int)$count !== count($this->requestTagsForUser($user))) {
			throw new \Exception("Expected $count tags, got ".count($this->requestTagsForUser($user)));
		}
	}

	/**
	 * Find tag by type and name
	 *
	 * @param string $type tag type
	 * @param string $tagName tag name
	 * @param string $user retrieved from which user
	 * @param bool $withGroups whether to also query the tag's groups
	 *
	 * @return array tag values or null if not found
	 */
	private function findTag($type, $tagName, $user = 'admin', $withGroups = false) {
		$tags = $this->requestTagsForUser($user, $withGroups);
		$userAssignable = 'true';
		$userVisible = 'true';
		switch ($type) {
			case 'normal':
				break;
			case 'not user-assignable':
				$userAssignable = 'false';
				break;
			case 'not user-visible':
				$userVisible = 'false';
				break;
			default:
				throw new \Exception('Unsupported type');
		}

		$foundTag = null;
		foreach ($tags as $tag) {
			if ($tag['display-name'] === $tagName
				&& $tag['user-visible'] === $userVisible
				&& $tag['user-assignable'] === $userAssignable) {
					$foundTag = $tag;
					break;
			}
		}
		return $foundTag;
	}

	/**
	 * @param string $name
	 * @return int
	 */
	private function findTagIdByName($name) {
		$tags = $this->requestTagsForUser('admin');
		$tagId = 0;
		foreach($tags as $id => $tag) {
			if($tag['display-name'] === $name) {
				$tagId = $id;
				break;
			}
		}
		return (int)$tagId;
	}

	/**
	 * @When :user edits the tag with name :oldNmae and sets its name to :newName
	 * @param string $user
	 * @param string $oldName
	 * @param string $newName
	 * @throws \Exception
	 */
	public function editsTheTagWithNameAndSetsItsNameTo($user, $oldName, $newName) {
		$tagId = $this->findTagIdByName($oldName);
		if($tagId === 0) {
			throw new \Exception('Could not find tag to rename');
		}

		try {
			$request = $this->client->createRequest(
				'PROPPATCH',
				$this->baseUrl . '/remote.php/dav/systemtags/' . $tagId,
				[
					'body' => '<?xml version="1.0"?>
<d:propertyupdate  xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:set>
   <d:prop>
      <oc:display-name>' . $newName . '</oc:display-name>
    </d:prop>
  </d:set>
</d:propertyupdate>',
					'auth' => [
						$user,
						$this->getPasswordForUser($user),
					],
				]
			);
			$this->response = $this->client->send($request);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @When :user edits the tag with name :oldNmae and sets its groups to :groups
	 * @param string $user
	 * @param string $oldName
	 * @param string $groups
	 * @throws \Exception
	 */
	public function editsTheTagWithNameAndSetsItsGroupsTo($user, $oldName, $groups) {
		$tagId = $this->findTagIdByName($oldName);
		if($tagId === 0) {
			throw new \Exception('Could not find tag to rename');
		}

		try {
			$request = $this->client->createRequest(
				'PROPPATCH',
				$this->baseUrl . '/remote.php/dav/systemtags/' . $tagId,
				[
					'body' => '<?xml version="1.0"?>
<d:propertyupdate  xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:set>
   <d:prop>
      <oc:groups>' . $groups . '</oc:groups>
    </d:prop>
  </d:set>
</d:propertyupdate>',
					'auth' => [
						$user,
						$this->getPasswordForUser($user),
					],
				]
			);
			$this->response = $this->client->send($request);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @When :user deletes the tag with name :name
	 * @param string $user
	 * @param string $name
	 */
	public function deletesTheTagWithName($user, $name)  {
		$tagId = $this->findTagIdByName($name);
		try {
			$this->response = $this->client->delete(
				$this->baseUrl . '/remote.php/dav/systemtags/' . $tagId,
				[
					'auth' => [
						$user,
						$this->getPasswordForUser($user),
					],
					'headers' => [
						'Content-Type' => 'application/json',
					],
				]
			);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @When /^"([^"]*)" adds the tag "([^"]*)" to "([^"]*)" (shared|owned) by "([^"]*)"$/
	 * @param string $taggingUser
	 * @param string $tagName
	 * @param string $fileName
	 * @param string $sharingUser
	 */
	public function addsTheTagToSharedBy($taggingUser, $tagName, $fileName, $sharedOrOwnedBy, $sharingUser) {
		$fileId = $this->getFileIdForPath($sharingUser, $fileName);
		$tagId = $this->findTagIdByName($tagName);

		try {
			$this->response = $this->client->put(
				$this->baseUrl.'/remote.php/dav/systemtags-relations/files/'.$fileId.'/'.$tagId,
				[
					'auth' => [
						$taggingUser,
						$this->getPasswordForUser($taggingUser),
					]
				]
			);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @Then /^"([^"]*)" (shared|owned) by "([^"]*)" has the following tags$/
	 * @param string $fileName
	 * @param string $sharingUser
	 * @param TableNode $table
	 * @throws \Exception
	 */
	public function sharedByHasTheFollowingTags($fileName, $sharedOrOwnedBy, $sharingUser, TableNode $table)  {
		$loadedExpectedTags = $table->getTable();
		$expectedTags = [];
		foreach($loadedExpectedTags as $expected) {
			$expectedTags[] = $expected[0];
		}

		// Get the real tags
		$request = $this->client->createRequest(
			'PROPFIND',
			$this->baseUrl.'/remote.php/dav/systemtags-relations/files/'.$this->getFileIdForPath($sharingUser, $fileName),
			[
				'auth' => [
					$sharingUser,
					$this->getPasswordForUser($sharingUser),
				],
				'body' => '<?xml version="1.0"?>
<d:propfind  xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:id />
    <oc:display-name />
    <oc:user-visible />
    <oc:user-assignable />
  </d:prop>
</d:propfind>',
			]
		);

		$response = $this->client->send($request)->getBody()->getContents();

		preg_match_all('/\<oc:display-name\>(.*)\<\/oc:display-name\>/', $response, $realTags);

		foreach($expectedTags as $key => $row) {
			foreach($realTags as $tag) {
				if($tag[0] === $row) {
					unset($expectedTags[$key]);
				}
			}
		}

		if(count($expectedTags) !== 0) {
			throw new \Exception('Not all tags found.');
		}
	}

	/**
	 * @Then :fileName shared by :sharingUser has the following tags for :user
	 * @param string $fileName
	 * @param string $sharingUser
	 * @param string $user
	 * @param TableNode $table
	 * @throws \Exception
	 */
	public function sharedByHasTheFollowingTagsFor($fileName, $sharingUser, $user, TableNode $table) {
		$loadedExpectedTags = $table->getTable();
		$expectedTags = [];
		foreach($loadedExpectedTags as $expected) {
			$expectedTags[] = $expected[0];
		}

		// Get the real tags
		try {
			$request = $this->client->createRequest(
				'PROPFIND',
				$this->baseUrl . '/remote.php/dav/systemtags-relations/files/' . $this->getFileIdForPath($sharingUser, $fileName),
				[
					'auth' => [
						$user,
						$this->getPasswordForUser($user),
					],
					'body' => '<?xml version="1.0"?>
<d:propfind  xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns">
  <d:prop>
    <oc:id />
    <oc:display-name />
    <oc:user-visible />
    <oc:user-assignable />
  </d:prop>
</d:propfind>',
				]
			);
			$this->response = $this->client->send($request)->getBody()->getContents();
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
		preg_match_all('/\<oc:display-name\>(.*?)\<\/oc:display-name\>/', $this->response, $realTags);

		$realTags = array_filter($realTags);
		$expectedTags = array_filter($expectedTags);

		foreach($expectedTags as $key => $row) {
			foreach($realTags as $tag) {
				foreach($tag as $index => $foo) {
					if($tag[$index] === $row) {
						unset($expectedTags[$key]);
					}
				}
			}
		}

		if(count($expectedTags) !== 0) {
			throw new \Exception('Not all tags found.');
		}
	}

	/**
	 * @When :user removes the tag :tagName from :fileName shared by :shareUser
	 * @param string $user
	 * @param string $tagName
	 * @param string $fileName
	 * @param string $shareUser
	 */
	public function removesTheTagFromSharedBy($user, $tagName, $fileName, $shareUser) {
		$tagId = $this->findTagIdByName($tagName);
		$fileId = $this->getFileIdForPath($shareUser, $fileName);

		try {
			$this->response = $this->client->delete(
				$this->baseUrl.'/remote.php/dav/systemtags-relations/files/'.$fileId.'/'.$tagId,
				[
					'auth' => [
						$user,
						$this->getPasswordForUser($user),
					],
				]
			);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @Given As :user sending :verb to :url with
	 * @param string $user
	 * @param string $verb
	 * @param string $url
	 * @param \Behat\Gherkin\Node\TableNode $body
	 * @throws \Exception
	 */
	public function asUserSendingToWith($user, $verb, $url, \Behat\Gherkin\Node\TableNode $body) {
		$client = new \GuzzleHttp\Client();
		$options = [];
		$options['auth'] = [$user, '123456'];
		$fd = $body->getRowsHash();
		$options['body'] = $fd;
		$client->send($client->createRequest($verb, $this->baseUrl.'/ocs/v1.php/'.$url, $options));
	}


	public function deleteTag($user, $tagID) {
		$tagsPath = '/systemtags/' . $tagID;
		try {
			$this->response = $this->makeDavRequest($user,
													"DELETE",
													$tagsPath,
													[],
													null,
													"uploads",
													null);
		} catch (\GuzzleHttp\Exception\ClientException $e) {
			$this->response = $e->getResponse();
		}
	}

	/**
	 * @BeforeScenario
	 * @AfterScenario
	 */
	public function cleanupTags()
	{
		foreach($this->createdTags as $tagID) {
			$this->deleteTag('admin', $tagID);
		}
	}
}
