<?php
/**
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Sergio Bertolin <sbertlin@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
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
use GuzzleHttp\Message\ResponseInterface;
use TestHelpers\TagsHelper;

/**
 * Tags functions
 */
trait Tags {

	/**
	 * @var array
	 */
	private $createdTags = [];

	/**
	 * @param string $user
	 * @param bool $userVisible
	 * @param bool $userAssignable
	 * @param string $name
	 * @param string $groups
	 *
	 * @return void
	 */
	private function createTag(
		$user, $userVisible, $userAssignable, $name, $groups = null
	) {
		$this->response = TagsHelper::createTag(
			$this->getBaseUrl(),
			$this->getActualUsername($user),
			$this->getPasswordForUser($user),
			$name, $userVisible, $userAssignable, $groups,
			$this->getDavPathVersion('systemtags')
		);
		$responseHeaders = $this->response->getHeaders();
		if (isset($responseHeaders['Content-Location'][0])) {
			$tagUrl = $responseHeaders['Content-Location'][0];
			$lastTagId = \substr($tagUrl, \strrpos($tagUrl, '/') + 1);
			\array_push($this->createdTags, $lastTagId);
		}
	}

	/**
	 * @param array $tagData
	 * @param string $type
	 *
	 * @return void
	 * @throws \Exception
	 */
	private function assertTypeOfTag($tagData, $type) {
		$userAttributes = TagsHelper::validateTypeOfTag($type);
		$tagDisplayName = $tagData['{http://owncloud.org/ns}display-name'];
		$userVisible = ($userAttributes[0]) ? 'true' : 'false';
		$userAssignable = ($userAttributes[1]) ? 'true' : 'false';
		if (($tagData['{http://owncloud.org/ns}user-visible'] !== $userVisible)
			|| ($tagData['{http://owncloud.org/ns}user-assignable'] !== $userAssignable)
		) {
			PHPUnit_Framework_Assert::fail(
				"tag $tagDisplayName is not of type $type"
			);
		}
	}

	/**
	 * @When user :user creates a :type tag with name :name using the WebDAV API
	 * @Given user :user has created a :type tag with name :name
	 *
	 * @param string $user
	 * @param string $type
	 * @param string $name
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function createsATagWithName($user, $type, $name) {
		$this->createTag(
			$user,
			TagsHelper::validateTypeOfTag($type)[0],
			TagsHelper::validateTypeOfTag($type)[1],
			$name
		);
	}

	/**
	 * @When user :user creates a :type tag with name :name and groups :groups using the WebDAV API
	 * @Given user :user has created a :type tag with name :name and groups :groups
	 *
	 * @param string $user
	 * @param string $type
	 * @param string $name
	 * @param string $groups
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function createsATagWithNameAndGroups($user, $type, $name, $groups) {
		$this->createTag(
			$user,
			TagsHelper::validateTypeOfTag($type)[0],
			TagsHelper::validateTypeOfTag($type)[1],
			$name,
			$groups
		);
	}

	/**
	 * @param string $user
	 * @param bool $withGroups
	 *
	 * @return array
	 */
	public function requestTagsForUser($user, $withGroups = false) {
		$this->response = TagsHelper:: requestTagsForUser(
			$this->getBaseUrl(),
			$this->getActualUsername($user),
			$this->getPasswordForUser($user),
			$withGroups
		);
		return $this->response;
	}

	/**
	 * @param string $user
	 * @param string $tagDisplayName
	 * @param bool $withGroups
	 *
	 * @return array|null
	 */
	public function requestTagByDisplayName(
		$user, $tagDisplayName, $withGroups = false
	) {
		$tagList = $this->requestTagsForUser($user, $withGroups);
		foreach ($tagList as $path => $tagData) {
			if (!empty($tagData)
				&& $tagData['{http://owncloud.org/ns}display-name'] === $tagDisplayName
			) {
				return $tagData;
			}
		}
		return null;
	}

	/**
	 * @Then the following tags should exist for :user
	 *
	 * @param string $user
	 * @param TableNode $table
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function theFollowingTagsShouldExistFor($user, TableNode $table) {
		$user = $this->getActualUsername($user);
		foreach ($table->getRowsHash() as $rowDisplayName => $rowType) {
			$tagData = $this->requestTagByDisplayName($user, $rowDisplayName);
			if ($tagData === null) {
				PHPUnit_Framework_Assert::fail(
					"tag $rowDisplayName is not in propfind answer"
				);
			} else {
				$this->assertTypeOfTag($tagData, $rowType);
			}
		}
	}

	/**
	 * @Then tag :tagDisplayName should not exist for :user
	 *
	 * @param string $tagDisplayName
	 * @param string $user
	 *
	 * @return void
	 */
	public function tagShouldNotExistForUser($tagDisplayName, $user) {
		$tagData = $this->requestTagByDisplayName($user, $tagDisplayName);
		PHPUnit_Framework_Assert::assertNull(
			$tagData, "tag $tagDisplayName is in propfind answer"
		);
	}

	/**
	 * @Then /^the user "([^"]*)" (should|should not) be able to assign the "([^"]*)" tag with name "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $shouldOrNot should or should not
	 * @param string $type
	 * @param string $tagDisplayName
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function theUserCanAssignTheTag(
		$user, $shouldOrNot, $type, $tagDisplayName
	) {
		$tagData = $this->requestTagByDisplayName($user, $tagDisplayName);
		$this->assertTypeOfTag($tagData, $type);
		if ($shouldOrNot === 'should') {
			$expected = 'true';
		} elseif ($shouldOrNot === 'should not') {
			$expected = 'false';
		} else {
			throw new \Exception(
				'Invalid condition, must be "should" or "should not"'
			);
		}
		if ($tagData['{http://owncloud.org/ns}can-assign'] !== $expected) {
			throw new \Exception('Tag cannot be assigned by user');
		}
	}

	/**
	 * @Then the :type tag with name :tagName should have the groups :groups
	 *
	 * @param string $type
	 * @param string $tagName
	 * @param string $groups list of groups separated by "|"
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function theTagHasGroup($type, $tagName, $groups) {
		$tagData = $this->requestTagByDisplayName(
			$this->getAdminUsername(), $tagName, true
		);
		PHPUnit_Framework_Assert::assertNotNull(
			$tagData, "Tag $tagName wasn't found for admin user"
		);
		$this->assertTypeOfTag($tagData, $type);
		PHPUnit_Framework_Assert::assertEquals(
			$tagData['{http://owncloud.org/ns}groups'],
			$groups,
			"Tag has groups '{$tagData['{http://owncloud.org/ns}groups']}' instead of the expected '$groups'"
		);
	}

	/**
	 * @Then :count tags should exist for :user
	 *
	 * @param int $count
	 * @param string $user
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function tagsShouldExistFor($count, $user) {
		if ((int)$count !== \count($this->requestTagsForUser($user))) {
			throw new \Exception(
				"Expected $count tags, got "
				. \count($this->requestTagsForUser($user))
			);
		}
	}

	/**
	 * @param string $name
	 *
	 * @return int
	 */
	private function findTagIdByName($name) {
		$tagData = $this->requestTagByDisplayName($this->getAdminUsername(), $name);
		return (int)$tagData['{http://owncloud.org/ns}id'];
	}

	/**
	 * @param string $user
	 * @param string $tagDisplayName
	 * @param string $properties optional
	 *
	 * @return ResponseInterface|null
	 */
	private function sendProppatchToSystemtags(
		$user, $tagDisplayName, $properties = null
	) {
		$client = $this->getSabreClient($user);
		$client->setThrowExceptions(true);
		$appPath = '/systemtags/';
		$tagID = $this->findTagIdByName($tagDisplayName);
		PHPUnit_Framework_Assert::assertNotNull($tagID, "Tag wasn't found");
		$fullUrl = $this->getBaseUrl()
			. '/' . $this->getDavPath('systemtags') . $appPath . $tagID;
		try {
			$response = $client->proppatch($fullUrl, $properties, 1);
			$this->response = $response;
			return $response;
		} catch (\Sabre\HTTP\ClientHttpException $e) {
			$this->response = null;
			return null;
		}
	}

	/**
	 * @When user :user edits the tag with name :oldName and sets its name to :newName using the WebDAV API
	 * @Given user :user has edited the tag with name :oldName and set its name to :newName
	 *
	 * @param string $user
	 * @param string $oldName
	 * @param string $newName
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function editTagName($user, $oldName, $newName) {
		$properties = [
						'{http://owncloud.org/ns}display-name' => $newName
					  ];
		$this->sendProppatchToSystemtags($user, $oldName, $properties);
	}

	/**
	 * @When user :user edits the tag with name :oldName and sets its groups to :groups using the WebDAV API
	 * @Given user :user has edited the tag with name :oldName and set its groups to :groups
	 *
	 * @param string $user
	 * @param string $oldName
	 * @param string $groups
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function editTagGroups($user, $oldName, $groups) {
		$user = $this->getActualUsername($user);
		$properties = [
						'{http://owncloud.org/ns}groups' => $groups
					  ];
		$this->sendProppatchToSystemtags($user, $oldName, $properties);
	}

	/**
	 * @When user :user deletes the tag with name :name using the WebDAV API
	 * @Given user :user has deleted the tag with name :name
	 *
	 * @param string $user
	 * @param string $name
	 *
	 * @return void
	 */
	public function userDeletesTag($user, $name) {
		$tagID = $this->findTagIdByName($name);
		$this->response = TagsHelper::deleteTag(
			$this->getBaseUrl(),
			$this->getActualUsername($user),
			$this->getPasswordForUser($user),
			$tagID,
			$this->getDavPathVersion('systemtags')
		);
		
		unset($this->createdTags[$tagID]);
	}

	/**
	 * @param string $taggingUser
	 * @param string $tagName
	 * @param string $fileName
	 * @param string $fileOwner
	 *
	 * @return void
	 */
	private function tag($taggingUser, $tagName, $fileName, $fileOwner) {
		$this->response = TagsHelper::tag(
			$this->getBaseUrl(),
			$taggingUser,
			$this->getPasswordForUser($taggingUser),
			$tagName,
			$fileName,
			$fileOwner,
			$this->getDavPathVersion('systemtags')
		);
	}

	/**
	 * @param string $user
	 * @param string $fileName
	 * @param string|null $sharingUser
	 *
	 * @return \Sabre\HTTP\ResponseInterface
	 */
	private function requestTagsForFile($user, $fileName, $sharingUser = null) {
		if ($sharingUser !== null) {
			$fileID = $this->getFileIdForPath($sharingUser, $fileName);
		} else {
			$fileID = $this->getFileIdForPath($user, $fileName);
		}
		$client = $this->getSabreClient($user);
		$properties = [
						'{http://owncloud.org/ns}id',
						'{http://owncloud.org/ns}display-name',
						'{http://owncloud.org/ns}user-visible',
						'{http://owncloud.org/ns}user-assignable',
						'{http://owncloud.org/ns}can-assign'
					  ];
		$appPath = '/systemtags-relations/files/';
		$fullUrl = $this->getBaseUrl()
			. '/' . $this->getDavPath('systemtags') . $appPath . $fileID;
		try {
			$response = $client->propfind($fullUrl, $properties, 1);
		} catch (Sabre\HTTP\ClientHttpException $e) {
			$response = $e->getResponse();
		}
		$this->response = $response;
		return $response;
	}

	/**
	 * @When /^user "([^"]*)" adds the tag "([^"]*)" to "([^"]*)" (?:shared|owned) by "([^"]*)" using the WebDAV API$/
	 * @Given /^user "([^"]*)" has added the tag "([^"]*)" to "([^"]*)" (?:shared|owned) by "([^"]*)"$/
	 *
	 * @param string $taggingUser
	 * @param string $tagName
	 * @param string $fileName
	 * @param string $sharingUser
	 *
	 * @return void
	 */
	public function addsTheTagToSharedBy(
		$taggingUser, $tagName, $fileName, $sharingUser
	) {
		$this->tag($taggingUser, $tagName, $fileName, $sharingUser);
	}

	/**
	 * @Then /^the HTTP status when user "([^"]*)" requests tags for (?:file|folder|entry) "([^"]*)" (?:shared|owned) by "([^"]*)" should be "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $fileName
	 * @param string $sharingUser
	 * @param string $status
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function theHttpStatusWhenuserRequestsTagsForEntryOwnedByShouldBe(
		$user, $fileName, $sharingUser, $status
	) {
		$response = $this->requestTagsForFile($user, $fileName, $sharingUser);
		PHPUnit_Framework_Assert::assertEquals($status, $response->getStatus());
	}

	/**
	 * @Then /^(?:file|folder|entry) "([^"]*)" (?:shared|owned) by "([^"]*)" should have the following tags$/
	 *
	 * @param string $fileName
	 * @param string $sharingUser
	 * @param TableNode $table
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function sharedByHasTheFollowingTags(
		$fileName, $sharingUser, TableNode $table
	) {
		$tagList = $this->requestTagsForFile($sharingUser, $fileName);
		PHPUnit_Framework_Assert::assertInternalType('array', $tagList);
		// The array of tags has a single "empty" item at the start.
		// Remove this entry.
		\array_shift($tagList);
		$found = false;
		foreach ($table->getRowsHash() as $rowDisplayName => $rowType) {
			$found = false;
			foreach ($tagList as $path => $tagData) {
				if (!empty($tagData)
					&& $tagData['{http://owncloud.org/ns}display-name'] === $rowDisplayName
				) {
					$found = true;
					$this->assertTypeOfTag($tagData, $rowType);
					break;
				}
			}
			if ($found === false) {
				PHPUnit_Framework_Assert::fail(
					"tag $rowDisplayName is not in propfind answer"
				);
			}
		}
		return $found;
	}

	/**
	 * @Then file :fileName should have the following tags for user :user
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param TableNode $table
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function fileHasTheFollowingTagsForUser(
		$fileName, $user, TableNode $table
	) {
		$this->sharedByHasTheFollowingTags($fileName, $user, $table);
	}

	/**
	 * @Then /^(?:file|folder|entry) "([^"]*)" (?:shared|owned) by "([^"]*)" should have no tags$/
	 *
	 * @param string $fileName
	 * @param string $sharingUser
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function sharedByHasNoTags($fileName, $sharingUser) {
		$tagList = $this->requestTagsForFile($sharingUser, $fileName);
		PHPUnit_Framework_Assert::assertInternalType('array', $tagList);
		// The array of tags has a single "empty" item at the start.
		// If there are no tags, then the array should have just this
		// one entry.
		PHPUnit_Framework_Assert::assertCount(1, $tagList);
	}

	/**
	 * @Then file :fileName should have no tags for user :user
	 *
	 * @param string $fileName
	 * @param string $user
	 *
	 * @return void
	 * @throws \Exception
	 */
	public function fileHasNoTagsForUser($fileName, $user) {
		$this->sharedByHasNoTags($fileName, $user);
	}

	/**
	 * @param string $untaggingUser
	 * @param string $tagName
	 * @param string $fileName
	 * @param string $fileOwner
	 *
	 * @return void
	 */
	private function untag($untaggingUser, $tagName, $fileName, $fileOwner) {
		$untaggingUser = $this->getActualUsername($untaggingUser);
		$fileOwner = $this->getActualUsername($fileOwner);
		$fileID = $this->getFileIdForPath($fileOwner, $fileName);
		$tagID = $this->findTagIdByName($tagName);
		$path = "/systemtags-relations/files/$fileID/$tagID";
		$this->response = $this->makeDavRequest(
			$untaggingUser,
			"DELETE",
			$path,
			null,
			null,
			"uploads",
			null,
			$this->getDavPathVersion('systemtags')
		);
	}

	/**
	 * @When user :user removes the tag :tagName from :fileName shared by :shareUser using the WebDAV API
	 * @Given user :user has removed the tag :tagName from :fileName shared by :shareUser
	 *
	 * @param string $user
	 * @param string $tagName
	 * @param string $fileName
	 * @param string $shareUser
	 *
	 * @return void
	 */
	public function removesTheTagFromSharedBy(
		$user, $tagName, $fileName, $shareUser
	) {
		$this->untag($user, $tagName, $fileName, $shareUser);
	}

	/**
	 * @BeforeScenario
	 * @AfterScenario
	 *
	 * @return void
	 */
	public function cleanupTags() {
		$this->deleteTokenAuthEnforcedAfterScenario();
		foreach ($this->createdTags as $tagID) {
			$this->response = TagsHelper::deleteTag(
				$this->getBaseUrl(),
				$this->getAdminUsername(),
				$this->getAdminPassword(),
				$tagID,
				2
			);
		}
	}
}
