<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
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

namespace Test\Files\External\Auth\Password;

use OC\Files\External\Auth\Password\SessionCredentials;
use OCP\ISession;
use OCP\Security\ICrypto;
use OCP\Files\External\IStorageConfig;
use OCP\IUser;

class SessionCredentialsTest extends \Test\TestCase {

	/** @var SessionCredentials | \PHPUnit_Framework_MockObject_MockObject */
	private $authMech;

	/** @var ISession | \PHPUnit_Framework_MockObject_MockObject */
	private $session;

	/** @var ICrypto | \PHPUnit_Framework_MockObject_MockObject */
	private $crypto;

	public function setUp() {
		parent::setUp();
		$this->session = $this->createMock(ISession::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->authMech = new SessionCredentials(
			$this->session,
			$this->crypto
		);
	}

	public function tearDown() {
		\OC_Hook::clear('OC_User', 'post_login');
		parent::tearDown();
	}

	public function testAuthenticateHookHandler() {
		$this->crypto->expects($this->once())
			->method('encrypt')
			->with('{"user":"user1","password":"pw"}')
			->willReturn('encrypted_stuff');
		$this->session->expects($this->once())
			->method('set')
			->with('password::sessioncredentials/credentials', 'encrypted_stuff');

		\OC_Hook::emit('OC_User', 'post_login', ['user' => 'user1', 'password' => 'pw']);
	}

	public function sessionDataProvider() {
		return [
			[
				[
					['password::sessioncredentials/credentials', 'encrypted_stuff'],
					['loginname', 'login1'],
				],
				'login1'
			],
			[
				[
					['password::sessioncredentials/credentials', 'encrypted_stuff'],
					['altloginname', 'altlogin1'],
					['loginname', 'login1'],
				],
				'altlogin1'
			],
		];
	}

	/**
	 * @dataProvider sessionDataProvider
	 */
	public function testManipulateStorageConfigSetsBackendOptions($sessionData, $expectedLogin) {
		$storageConfig = $this->createMock(IStorageConfig::class);
		$user = $this->createMock(IUser::class);

		$this->session->method('get')->will($this->returnValueMap($sessionData));
		$this->session->method('exists')->will($this->returnCallback(function($key) {
			return null !== $this->session->get($key);
		}));

		$this->crypto->expects($this->once())
			->method('decrypt')
			->with('encrypted_stuff')
			->willReturn('{"user":"user1","password":"pw"}');

		$storageConfig->expects($this->at(0))
			->method('setBackendOption')
			->with('user', $expectedLogin);
		$storageConfig->expects($this->at(1))
			->method('setBackendOption')
			->with('password', 'pw');

		$this->authMech->manipulateStorageConfig($storageConfig, $user);
	}

	/**
	 * @expectedException \OCP\Files\External\InsufficientDataForMeaningfulAnswerException
	 */
	public function testManipulateStorageConfigFailsWhenEmptyCredentials() {
		$storageConfig = $this->createMock(IStorageConfig::class);
		$user = $this->createMock(IUser::class);

		$this->session->expects($this->once())
			->method('get')
			->with('password::sessioncredentials/credentials')
			->willReturn(null);

		$this->authMech->manipulateStorageConfig($storageConfig, $user);
	}
}
