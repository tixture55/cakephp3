<?php
/**
 * AuthComponentTest file
 *
 * CakePHP(tm) Tests <http://book.cakephp.org/2.0/en/development/testing.html>
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://book.cakephp.org/2.0/en/development/testing.html CakePHP(tm) Tests
 * @since         CakePHP(tm) v 1.2.0.5347
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Controller\Component;

use Cake\Controller\ComponentRegistry;
use Cake\Controller\Component\AuthComponent;
use Cake\Controller\Component\SessionComponent;
use Cake\Controller\Controller;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Error;
use Cake\Event\Event;
use Cake\Network\Request;
use Cake\Network\Response;
use Cake\Network\Session;
use Cake\ORM\Entity;
use Cake\ORM\TableRegistry;
use Cake\Routing\Dispatcher;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use Cake\Utility\Security;
use TestApp\Controller\AuthTestController;
use TestApp\Controller\Component\TestAuthComponent;

/**
 * AuthComponentTest class
 *
 */
class AuthComponentTest extends TestCase {

/**
 * name property
 *
 * @var string
 */
	public $name = 'Auth';

/**
 * fixtures property
 *
 * @var array
 */
	public $fixtures = ['core.user', 'core.auth_user'];

/**
 * initialized property
 *
 * @var boolean
 */
	public $initialized = false;

/**
 * setUp method
 *
 * @return void
 */
	public function setUp() {
		parent::setUp();

		Configure::write('Security.salt', 'YJfIxfs2guVoUubWDYhG93b0qyJfIxfs2guwvniR2G0FgaC9mi');
		Configure::write('App.namespace', 'TestApp');

		$request = new Request();

		$this->Controller = new AuthTestController($request, $this->getMock('Cake\Network\Response'));
		$this->Controller->constructClasses();

		$this->Auth = new TestAuthComponent($this->Controller->Components);
		$this->Auth->request = $request;
		$this->Auth->response = $this->getMock('Cake\Network\Response');
		AuthComponent::$sessionKey = 'Auth.User';

		$this->initialized = true;
		Router::reload();
		Router::connect('/:controller/:action/*');

		$Users = TableRegistry::get('AuthUsers');
		$Users->updateAll(['password' => Security::hash('cake', 'blowfish', false)], []);
	}

/**
 * tearDown method
 *
 * @return void
 */
	public function tearDown() {
		parent::tearDown();

		TestAuthComponent::clearUser();
		$this->Auth->Session->delete('Auth');
		$this->Auth->Session->delete('Message.auth');
		unset($this->Controller, $this->Auth);
	}

/**
 * testNoAuth method
 *
 * @return void
 */
	public function testNoAuth() {
		$this->assertFalse($this->Auth->isAuthorized());
	}

/**
 * testIsErrorOrTests
 *
 * @return void
 */
	public function testIsErrorOrTests() {
		$event = new Event('Controller.startup', $this->Controller);
		$this->Controller->Auth->initialize($event);

		$this->Controller->name = 'Error';
		$this->assertTrue($this->Controller->Auth->startup($event));

		$this->Controller->name = 'Post';
		$this->Controller->request['action'] = 'thisdoesnotexist';
		$this->assertTrue($this->Controller->Auth->startup($event));

		$this->Controller->scaffold = null;
		$this->Controller->request['action'] = 'index';
		$this->assertFalse($this->Controller->Auth->startup($event));
	}

/**
 * testLogin method
 *
 * @return void
 */
	public function testLogin() {
		$this->getMock('Cake\Controller\Component\Auth\FormAuthenticate', array(), array(), 'AuthLoginFormAuthenticate', false);
		class_alias('AuthLoginFormAuthenticate', 'Cake\Controller\Component\Auth\AuthLoginFormAuthenticate');
		$this->Auth->authenticate = array(
			'AuthLoginForm' => array(
				'userModel' => 'AuthUsers'
			)
		);
		$this->Auth->Session = $this->getMock('Cake\Controller\Component\SessionComponent', array('renew'), array(), '', false);

		$mocks = $this->Auth->constructAuthenticate();
		$this->mockObjects[] = $mocks[0];

		$this->Auth->request->data = array(
			'AuthUsers' => array(
				'username' => 'mark',
				'password' => Security::hash('cake', null, true)
			)
		);

		$user = array(
			'id' => 1,
			'username' => 'mark'
		);

		$mocks[0]->expects($this->once())
			->method('authenticate')
			->with($this->Auth->request)
			->will($this->returnValue($user));

		$this->Auth->Session->expects($this->once())
			->method('renew');

		$result = $this->Auth->login();
		$this->assertTrue($result);

		$this->assertTrue((bool)$this->Auth->user());
		$this->assertEquals($user, $this->Auth->user());
	}

/**
 * testRedirectVarClearing method
 *
 * @return void
 */
	public function testRedirectVarClearing() {
		$this->Controller->request['controller'] = 'auth_test';
		$this->Controller->request['action'] = 'admin_add';
		$this->Controller->request->here = '/auth_test/admin_add';
		$this->assertNull($this->Auth->Session->read('Auth.redirect'));

		$this->Auth->authenticate = array('Form');
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$this->assertEquals('/auth_test/admin_add', $this->Auth->Session->read('Auth.redirect'));

		$this->Auth->Session->write('Auth.User', array('username' => 'admad'));
		$this->Auth->startup($event, $this->Controller);
		$this->assertNull($this->Auth->Session->read('Auth.redirect'));
	}

/**
 * testAuthorizeFalse method
 *
 * @return void
 */
	public function testAuthorizeFalse() {
		$event = new Event('Controller.startup', $this->Controller);
		$Users = TableRegistry::get('Users');
		$user = $Users->find('all')->hydrate(false)->first();
		$this->Auth->Session->write('Auth.User', $user);
		$this->Controller->Auth->userModel = 'Users';
		$this->Controller->Auth->authorize = false;
		$this->Controller->request->addParams(Router::parse('auth_test/add'));
		$this->Controller->Auth->initialize($event);
		$result = $this->Controller->Auth->startup($event);
		$this->assertTrue($result);

		$this->Auth->Session->delete('Auth');
		$result = $this->Controller->Auth->startup($event);
		$this->assertFalse($result);
		$this->assertTrue($this->Auth->Session->check('Message.auth'));

		$this->Controller->request->addParams(Router::parse('auth_test/camelCase'));
		$result = $this->Controller->Auth->startup($event);
		$this->assertFalse($result);
	}

/**
 * @expectedException Cake\Error\Exception
 * @return void
 */
	public function testIsAuthorizedMissingFile() {
		$this->Controller->Auth->authorize = 'Missing';
		$this->Controller->Auth->isAuthorized(array('User' => array('id' => 1)));
	}

/**
 * test that isAuthorized calls methods correctly
 *
 * @return void
 */
	public function testIsAuthorizedDelegation() {
		$this->getMock('Cake\Controller\Component\Auth\BaseAuthorize', array('authorize'), array(), 'AuthMockOneAuthorize', false);
		$this->getMock('Cake\Controller\Component\Auth\BaseAuthorize', array('authorize'), array(), 'AuthMockTwoAuthorize', false);
		$this->getMock('Cake\Controller\Component\Auth\BaseAuthorize', array('authorize'), array(), 'AuthMockThreeAuthorize', false);

		class_alias('AuthMockOneAuthorize', 'Cake\Controller\Component\Auth\AuthMockOneAuthorize');
		class_alias('AuthMockTwoAuthorize', 'Cake\Controller\Component\Auth\AuthMockTwoAuthorize');
		class_alias('AuthMockThreeAuthorize', 'Cake\Controller\Component\Auth\AuthMockThreeAuthorize');

		$this->Auth->authorize = array(
			'AuthMockOne',
			'AuthMockTwo',
			'AuthMockThree'
		);
		$mocks = $this->Auth->constructAuthorize();
		$request = $this->Auth->request;

		$this->assertEquals(3, count($mocks));
		$mocks[0]->expects($this->once())
			->method('authorize')
			->with(array('User'), $request)
			->will($this->returnValue(false));

		$mocks[1]->expects($this->once())
			->method('authorize')
			->with(array('User'), $request)
			->will($this->returnValue(true));

		$mocks[2]->expects($this->never())
			->method('authorize');

		$this->assertTrue($this->Auth->isAuthorized(array('User'), $request));
	}

/**
 * test that isAuthorized will use the session user if none is given.
 *
 * @return void
 */
	public function testIsAuthorizedUsingUserInSession() {
		$this->getMock('Cake\Controller\Component\Auth\BaseAuthorize', array('authorize'), array(), 'AuthMockFourAuthorize', false);
		class_alias('AuthMockFourAuthorize', 'Cake\Controller\Component\Auth\AuthMockFourAuthorize');
		$this->Auth->authorize = array('AuthMockFour');

		$user = array('user' => 'mark');
		$this->Auth->Session->write('Auth.User', $user);
		$mocks = $this->Auth->constructAuthorize();
		$request = $this->Controller->request;

		$mocks[0]->expects($this->once())
			->method('authorize')
			->with($user, $request)
			->will($this->returnValue(true));

		$this->assertTrue($this->Auth->isAuthorized(null, $request));
	}

/**
 * test that loadAuthorize resets the loaded objects each time.
 *
 * @return void
 */
	public function testLoadAuthorizeResets() {
		$this->Controller->Auth->authorize = array(
			'Controller'
		);
		$result = $this->Controller->Auth->constructAuthorize();
		$this->assertEquals(1, count($result));

		$result = $this->Controller->Auth->constructAuthorize();
		$this->assertEquals(1, count($result));
	}

/**
 * @expectedException Cake\Error\Exception
 * @return void
 */
	public function testLoadAuthenticateNoFile() {
		$this->Controller->Auth->authenticate = 'Missing';
		$this->Controller->Auth->identify($this->Controller->request, $this->Controller->response);
	}

/**
 * test the * key with authenticate
 *
 * @return void
 */
	public function testAllConfigWithAuthorize() {
		$this->Controller->Auth->authorize = array(
			AuthComponent::ALL => array('actionPath' => 'controllers/'),
			'Actions'
		);
		$objects = $this->Controller->Auth->constructAuthorize();
		$result = $objects[0];
		$this->assertEquals('controllers/', $result->settings['actionPath']);
	}

/**
 * test that loadAuthorize resets the loaded objects each time.
 *
 * @return void
 */
	public function testLoadAuthenticateResets() {
		$this->Controller->Auth->authenticate = array(
			'Form'
		);
		$result = $this->Controller->Auth->constructAuthenticate();
		$this->assertEquals(1, count($result));

		$result = $this->Controller->Auth->constructAuthenticate();
		$this->assertEquals(1, count($result));
	}

/**
 * test the * key with authenticate
 *
 * @return void
 */
	public function testAllConfigWithAuthenticate() {
		$this->Controller->Auth->authenticate = array(
			AuthComponent::ALL => array('userModel' => 'AuthUsers'),
			'Form'
		);
		$objects = $this->Controller->Auth->constructAuthenticate();
		$result = $objects[0];
		$this->assertEquals('AuthUsers', $result->settings['userModel']);
	}

/**
 * Tests that deny always takes precedence over allow
 *
 * @return void
 */
	public function testAllowDenyAll() {
		$event = new Event('Controller.startup', $this->Controller);
		$this->Controller->Auth->initialize($event);

		$this->Controller->Auth->allow();
		$this->Controller->Auth->deny('add', 'camelCase');

		$this->Controller->request['action'] = 'delete';
		$this->assertTrue($this->Controller->Auth->startup($event));

		$this->Controller->request['action'] = 'add';
		$this->assertFalse($this->Controller->Auth->startup($event));

		$this->Controller->request['action'] = 'camelCase';
		$this->assertFalse($this->Controller->Auth->startup($event));

		$this->Controller->Auth->allow();
		$this->Controller->Auth->deny(array('add', 'camelCase'));

		$this->Controller->request['action'] = 'delete';
		$this->assertTrue($this->Controller->Auth->startup($event));

		$this->Controller->request['action'] = 'camelCase';
		$this->assertFalse($this->Controller->Auth->startup($event));

		$this->Controller->Auth->allow('*');
		$this->Controller->Auth->deny();

		$this->Controller->request['action'] = 'camelCase';
		$this->assertFalse($this->Controller->Auth->startup($event));

		$this->Controller->request['action'] = 'add';
		$this->assertFalse($this->Controller->Auth->startup($event));

		$this->Controller->Auth->allow('camelCase');
		$this->Controller->Auth->deny();

		$this->Controller->request['action'] = 'camelCase';
		$this->assertFalse($this->Controller->Auth->startup($event));

		$this->Controller->request['action'] = 'login';
		$this->assertFalse($this->Controller->Auth->startup($event));

		$this->Controller->Auth->deny();
		$this->Controller->Auth->allow(null);

		$this->Controller->request['action'] = 'camelCase';
		$this->assertTrue($this->Controller->Auth->startup($event));

		$this->Controller->Auth->allow();
		$this->Controller->Auth->deny(null);

		$this->Controller->request['action'] = 'camelCase';
		$this->assertFalse($this->Controller->Auth->startup($event));
	}

/**
 * test that deny() converts camel case inputs to lowercase.
 *
 * @return void
 */
	public function testDenyWithCamelCaseMethods() {
		$event = new Event('Controller.startup', $this->Controller);
		$this->Controller->Auth->initialize($event);
		$this->Controller->Auth->allow();
		$this->Controller->Auth->deny('add', 'camelCase');

		$url = '/auth_test/camelCase';
		$this->Controller->request->addParams(Router::parse($url));
		$this->Controller->request->query['url'] = Router::normalize($url);

		$this->assertFalse($this->Controller->Auth->startup($event));

		$url = '/auth_test/CamelCase';
		$this->Controller->request->addParams(Router::parse($url));
		$this->Controller->request->query['url'] = Router::normalize($url);
		$this->assertFalse($this->Controller->Auth->startup($event));
	}

/**
 * test that allow() and allowedActions work with camelCase method names.
 *
 * @return void
 */
	public function testAllowedActionsWithCamelCaseMethods() {
		$event = new Event('Controller.startup', $this->Controller);
		$url = '/auth_test/camelCase';
		$this->Controller->request->addParams(Router::parse($url));
		$this->Controller->request->query['url'] = Router::normalize($url);
		$this->Controller->Auth->initialize($event);
		$this->Controller->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$this->Controller->Auth->userModel = 'AuthUsers';
		$this->Controller->Auth->allow();
		$result = $this->Controller->Auth->startup($event);
		$this->assertTrue($result, 'startup() should return true, as action is allowed. %s');

		$url = '/auth_test/camelCase';
		$this->Controller->request->addParams(Router::parse($url));
		$this->Controller->request->query['url'] = Router::normalize($url);
		$this->Controller->Auth->initialize($event);
		$this->Controller->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$this->Controller->Auth->userModel = 'AuthUsers';
		$this->Controller->Auth->allowedActions = array('delete', 'camelCase', 'add');
		$result = $this->Controller->Auth->startup($event);
		$this->assertTrue($result, 'startup() should return true, as action is allowed. %s');

		$this->Controller->Auth->allowedActions = array('delete', 'add');
		$result = $this->Controller->Auth->startup($event);
		$this->assertFalse($result, 'startup() should return false, as action is not allowed. %s');

		$url = '/auth_test/delete';
		$this->Controller->request->addParams(Router::parse($url));
		$this->Controller->request->query['url'] = Router::normalize($url);
		$this->Controller->Auth->initialize($event);
		$this->Controller->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$this->Controller->Auth->userModel = 'AuthUsers';

		$this->Controller->Auth->allow(array('delete', 'add'));
		$result = $this->Controller->Auth->startup($event);
		$this->assertTrue($result, 'startup() should return true, as action is allowed. %s');
	}

	public function testAllowedActionsSetWithAllowMethod() {
		$url = '/auth_test/action_name';
		$this->Controller->request->addParams(Router::parse($url));
		$this->Controller->request->query['url'] = Router::normalize($url);
		$event = new Event('Controller.initialize', $this->Controller);
		$this->Controller->Auth->initialize($event);
		$this->Controller->Auth->allow('action_name', 'anotherAction');
		$this->assertEquals(array('action_name', 'anotherAction'), $this->Controller->Auth->allowedActions);
	}

/**
 * testLoginRedirect method
 *
 * @return void
 */
	public function testLoginRedirect() {
		$url = '/auth_test/camelCase';

		$this->Auth->Session->write('Auth', array(
			'AuthUsers' => array('id' => '1', 'username' => 'nate')
		));

		$this->Auth->request->addParams(Router::parse('users/login'));
		$this->Auth->request->url = 'users/login';
		$this->Auth->request->env('HTTP_REFERER', false);
		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);

		$this->Auth->loginRedirect = array(
			'controller' => 'pages', 'action' => 'display', 'welcome'
		);
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize($this->Auth->loginRedirect);
		$this->assertEquals($expected, $this->Auth->redirectUrl());

		$this->Auth->Session->delete('Auth');

		$url = '/posts/view/1';

		$this->Auth->Session->write('Auth', array(
			'AuthUsers' => array('id' => '1', 'username' => 'nate'))
		);
		$this->Controller->testUrl = null;
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->env('HTTP_REFERER', false);
		array_push($this->Controller->methods, 'view', 'edit', 'index');

		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);
		$this->Auth->authorize = 'controller';

		$this->Auth->loginAction = array(
			'controller' => 'AuthTest', 'action' => 'login'
		);
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize('/AuthTest/login');
		$this->assertEquals($expected, $this->Controller->testUrl);

		$this->Auth->Session->delete('Auth');
		$this->Auth->Session->write('Auth', array(
			'AuthUsers' => array('id' => '1', 'username' => 'nate')
		));
		$this->Auth->request->params['action'] = 'login';
		$this->Auth->request->url = 'auth_test/login';
		$this->Controller->request->env('HTTP_REFERER', Router::url('/admin', true));
		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);
		$this->Auth->loginAction = 'auth_test/login';
		$this->Auth->loginRedirect = false;
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize('/admin');
		$this->assertEquals($expected, $this->Auth->redirectUrl());

		// Passed Arguments
		$this->Auth->Session->delete('Auth');
		$url = '/posts/view/1';
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->url = $this->Auth->request->here = Router::normalize($url);
		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);
		$this->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize('posts/view/1');
		$this->assertEquals($expected, $this->Auth->Session->read('Auth.redirect'));

		// QueryString parameters
		$this->Auth->Session->delete('Auth');
		$url = '/posts/index/29';
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->url = $this->Auth->request->here = Router::normalize($url);
		$this->Auth->request->query = array(
			'print' => 'true',
			'refer' => 'menu'
		);

		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);
		$this->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize('posts/index/29?print=true&refer=menu');
		$this->assertEquals($expected, $this->Auth->Session->read('Auth.redirect'));

		// Different base urls.
		$appConfig = Configure::read('App');

		Configure::write('App', array(
			'dir' => APP_DIR,
			'webroot' => WEBROOT_DIR,
			'base' => false,
			'baseUrl' => '/cake/index.php'
		));

		$this->Auth->Session->delete('Auth');

		$url = '/posts/add';
		$this->Auth->request = $this->Controller->request = new Request($url);
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->url = Router::normalize($url);

		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);
		$this->Auth->loginAction = array('controller' => 'users', 'action' => 'login');
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize('/posts/add');
		$this->assertEquals($expected, $this->Auth->Session->read('Auth.redirect'));

		$this->Auth->Session->delete('Auth');
		Configure::write('App', $appConfig);

		// External Authed Action
		$this->Auth->Session->delete('Auth');
		$url = '/posts/edit/1';
		$request = new Request($url);
		$request->env('HTTP_REFERER', 'http://webmail.example.com/view/message');
		$request->query = array();
		$this->Auth->request = $this->Controller->request = $request;
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->url = $this->Auth->request->here = Router::normalize($url);
		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);
		$this->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize('/posts/edit/1');
		$this->assertEquals($expected, $this->Auth->Session->read('Auth.redirect'));

		// External Direct Login Link
		$this->Auth->Session->delete('Auth');
		$url = '/AuthTest/login';
		$this->Auth->request = $this->Controller->request = new Request($url);
		$this->Auth->request->env('HTTP_REFERER', 'http://webmail.example.com/view/message');
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->url = Router::normalize($url);
		$event = new Event('Controller.initialize', $this->Controller);
		$this->Auth->initialize($event);
		$this->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->startup($event);
		$expected = Router::normalize('/');
		$this->assertEquals($expected, $this->Auth->Session->read('Auth.redirect'));

		$this->Auth->Session->delete('Auth');
	}

/**
 * testNoLoginRedirectForAuthenticatedUser method
 *
 * @return void
 */
	public function testNoLoginRedirectForAuthenticatedUser() {
		$this->Controller->request['controller'] = 'auth_test';
		$this->Controller->request['action'] = 'login';
		$this->Controller->here = '/auth_test/login';
		$this->Auth->request->url = 'auth_test/login';

		$this->Auth->Session->write('Auth.User.id', '1');
		$this->Auth->authenticate = array('Form');
		$this->getMock('BaseAuthorize', array('authorize'), array(), 'NoLoginRedirectMockAuthorize', false);
		$this->Auth->authorize = array('NoLoginRedirectMockAuthorize');
		$this->Auth->loginAction = array('controller' => 'auth_test', 'action' => 'login');

		$event = new Event('Controller.startup', $this->Controller);
		$return = $this->Auth->startup($event);
		$this->assertTrue($return);
		$this->assertNull($this->Controller->testUrl);
	}

/**
 * Default to loginRedirect, if set, on authError.
 *
 * @return void
 */
	public function testDefaultToLoginRedirect() {
		$url = '/party/on';
		$this->Auth->request = $Request = new Request($url);
		$Request->env('HTTP_REFERER', false);
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->authorize = array('Controller');
		$this->Auth->login(array('username' => 'mariano', 'password' => 'cake'));
		$this->Auth->loginRedirect = array(
			'controller' => 'something', 'action' => 'else',
		);

		$response = new Response();
		$Controller = $this->getMock(
			'Cake\Controller\Controller',
			array('on', 'redirect'),
			array($Request, $response)
		);
		$event = new Event('Controller.startup', $Controller);

		$expected = Router::url($this->Auth->loginRedirect, true);
		$Controller->expects($this->once())
			->method('redirect')
			->with($this->equalTo($expected));
		$this->Auth->startup($event);
	}

/**
 * testRedirectToUnauthorizedRedirect
 *
 * @return void
 */
	public function testRedirectToUnauthorizedRedirect() {
		$url = '/party/on';
		$this->Auth->request = $request = new Request($url);
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->authorize = array('Controller');
		$this->Auth->login(array('username' => 'admad', 'password' => 'cake'));

		$expected = ['controller' => 'no_can_do', 'action' => 'jack'];
		$this->Auth->unauthorizedRedirect = $expected;

		$response = new Response();
		$Controller = $this->getMock(
			'Cake\Controller\Controller',
			array('on', 'redirect'),
			array($request, $response)
		);
		$this->Auth->Session = $this->getMock(
			'Cake\Controller\Component\SessionComponent',
			array('setFlash'),
			array($Controller->Components)
		);

		$Controller->expects($this->once())
			->method('redirect')
			->with($this->equalTo($expected));

		$this->Auth->Session->expects($this->once())
			->method('setFlash');

		$event = new Event('Controller.startup', $Controller);
		$this->Auth->startup($event);
	}

/**
 * testRedirectToUnauthorizedRedirectSuppressedAuthError
 *
 * @return void
 */
	public function testRedirectToUnauthorizedRedirectSuppressedAuthError() {
		$url = '/party/on';
		$this->Auth->request = $Request = new Request($url);
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->authorize = array('Controller');
		$this->Auth->login(array('username' => 'admad', 'password' => 'cake'));
		$expected = ['controller' => 'no_can_do', 'action' => 'jack'];
		$this->Auth->unauthorizedRedirect = $expected;
		$this->Auth->authError = false;

		$Response = new Response();
		$Controller = $this->getMock(
			'Cake\Controller\Controller',
			array('on', 'redirect'),
			array($Request, $Response)
		);
		$this->Auth->Session = $this->getMock(
			'Cake\Controller\Component\SessionComponent',
			array('setFlash'),
			array($Controller->Components)
		);

		$Controller->expects($this->once())
			->method('redirect')
			->with($this->equalTo($expected));

		$this->Auth->Session->expects($this->never())
			->method('setFlash');

		$event = new Event('Controller.startup', $Controller);
		$this->Auth->startup($event);
	}

/**
 * Throw ForbiddenException if AuthComponent::$unauthorizedRedirect set to false
 * @expectedException Cake\Error\ForbiddenException
 * @return void
 */
	public function testForbiddenException() {
		$url = '/party/on';
		$this->Auth->request = $request = new Request($url);
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->authorize = array('Controller');
		$this->Auth->unauthorizedRedirect = false;
		$this->Auth->login(array('username' => 'baker', 'password' => 'cake'));

		$response = new Response();
		$Controller = $this->getMock(
			'Cake\Controller\Controller',
			array('on', 'redirect'),
			array($request, $response)
		);

		$event = new Event('Controller.startup', $Controller);
		$this->Auth->startup($event);
	}

/**
 * Test that no redirects or authorization tests occur on the loginAction
 *
 * @return void
 */
	public function testNoRedirectOnLoginAction() {
		$event = new Event('Controller.startup', $this->Controller);
		$controller = $this->getMock('Cake\Controller\Controller');
		$controller->methods = array('login');

		$url = '/AuthTest/login';
		$this->Auth->request = $controller->request = new Request($url);
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->loginAction = array('controller' => 'AuthTest', 'action' => 'login');
		$this->Auth->authorize = array('Controller');

		$controller->expects($this->never())
			->method('redirect');

		$this->Auth->startup($event);
	}

/**
 * Ensure that no redirect is performed when a 404 is reached
 * And the user doesn't have a session.
 *
 * @return void
 */
	public function testNoRedirectOn404() {
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->Session->delete('Auth');
		$this->Auth->initialize($event);
		$this->Auth->request->addParams(Router::parse('auth_test/something_totally_wrong'));
		$result = $this->Auth->startup($event);
		$this->assertTrue($result, 'Auth redirected a missing action %s');
	}

/**
 * testAdminRoute method
 *
 * @return void
 */
	public function testAdminRoute() {
		$event = new Event('Controller.startup', $this->Controller);
		$pref = Configure::read('Routing.prefixes');
		Configure::write('Routing.prefixes', array('admin'));
		Router::reload();
		require CAKE . 'Config/routes.php';

		$url = '/admin/auth_test/add';
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->query['url'] = ltrim($url, '/');
		$this->Auth->request->base = '';

		Router::setRequestInfo($this->Auth->request);
		$this->Auth->initialize($event);

		$this->Auth->loginAction = array(
			'prefix' => 'admin', 'controller' => 'auth_test', 'action' => 'login'
		);

		$this->Auth->startup($event);
		$this->assertEquals('/admin/auth_test/login', $this->Controller->testUrl);

		Configure::write('Routing.prefixes', $pref);
	}

/**
 * testAjaxLogin method
 *
 * @return void
 */
	public function testAjaxLogin() {
		ob_start();
		$request = new Request([
			'url' => '/ajax_auth/add',
			'environment' => ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']
		]);
		$Dispatcher = new Dispatcher();
		$Dispatcher->dispatch($request, new Response(), array('return' => 1));
		$result = ob_get_clean();

		$this->assertEquals("Ajax!\nthis is the test element", str_replace("\r\n", "\n", $result));
	}

/**
 * testLoginActionRedirect method
 *
 * @return void
 */
	public function testLoginActionRedirect() {
		$event = new Event('Controller.startup', $this->Controller);
		Configure::write('Routing.prefixes', array('admin'));
		Router::reload();
		require CAKE . 'Config/routes.php';

		$url = '/admin/auth_test/login';
		$request = $this->Auth->request;
		$request->addParams([
			'plugin' => null,
			'controller' => 'auth_test',
			'action' => 'login',
			'prefix' => 'admin',
			'pass' => [],
		])->addPaths([
			'base' => null,
			'here' => $url,
			'webroot' => '/',
		]);
		$request->url = ltrim($url, '/');
		Router::setRequestInfo($request);

		$this->Auth->initialize($event);
		$this->Auth->loginAction = [
			'prefix' => 'admin',
			'controller' => 'auth_test',
			'action' => 'login'
		];
		$this->Auth->startup($event);

		$this->assertNull($this->Controller->testUrl);
	}

/**
 * Stateless auth methods like Basic should populate data that can be
 * accessed by $this->user().
 *
 * @return void
 */
	public function testStatelessAuthWorksWithUser() {
		$event = new Event('Controller.startup', $this->Controller);
		$url = '/auth_test/add';
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->env('PHP_AUTH_USER', 'mariano');
		$this->Auth->request->env('PHP_AUTH_PW', 'cake');

		$this->Auth->authenticate = array(
			'Basic' => array('userModel' => 'AuthUsers')
		);
		$this->Auth->startup($event);

		$result = $this->Auth->user();
		$this->assertEquals('mariano', $result['username']);

		$result = $this->Auth->user('username');
		$this->assertEquals('mariano', $result);
	}

/**
 * test $settings in Controller::$components
 *
 * @return void
 */
	public function testComponentSettings() {
		$request = new Request();
		$this->Controller = new AuthTestController($request, $this->getMock('Cake\Network\Response'));

		$this->Controller->components = array(
			'Auth' => array(
				'loginAction' => array('controller' => 'people', 'action' => 'login'),
				'logoutRedirect' => array('controller' => 'people', 'action' => 'login'),
			),
			'Session'
		);
		$this->Controller->constructClasses();

		$expected = array(
			'loginAction' => array('controller' => 'people', 'action' => 'login'),
			'logoutRedirect' => array('controller' => 'people', 'action' => 'login'),
		);
		$this->assertEquals($expected['loginAction'], $this->Controller->Auth->loginAction);
		$this->assertEquals($expected['logoutRedirect'], $this->Controller->Auth->logoutRedirect);
	}

/**
 * test that logout deletes the session variables. and returns the correct URL
 *
 * @return void
 */
	public function testLogout() {
		$this->Auth->Session->write('Auth.User.id', '1');
		$this->Auth->Session->write('Auth.redirect', '/users/login');
		$this->Auth->logoutRedirect = '/';
		$result = $this->Auth->logout();

		$this->assertEquals('/', $result);
		$this->assertNull($this->Auth->Session->read('Auth.AuthUsers'));
		$this->assertNull($this->Auth->Session->read('Auth.redirect'));
	}

/**
 * Logout should trigger a logout method on authentication objects.
 *
 * @return void
 */
	public function testLogoutTrigger() {
		$this->getMock('Cake\Controller\Component\Auth\BaseAuthenticate', array('authenticate', 'logout'), array(), 'LogoutTriggerMockAuthenticate', false);
		class_alias('LogoutTriggerMockAuthenticate', 'Cake\Controller\Component\Auth\LogoutTriggerMockAuthenticate');

		$this->Auth->authenticate = array('LogoutTriggerMock');
		$mock = $this->Auth->constructAuthenticate();
		$mock[0]->expects($this->once())
			->method('logout');

		$this->Auth->logout();
	}

/**
 * test mapActions loading and delegating to authorize objects.
 *
 * @return void
 */
	public function testMapActionsDelegation() {
		$this->getMock('Cake\Controller\Component\Auth\BaseAuthorize', array('authorize'), array(), 'MapActionMockAuthorize', false);
		class_alias('MapActionMockAuthorize', 'Cake\Controller\Component\Auth\MapActionMockAuthorize');
		$this->Auth->authorize = array('MapActionMock');
		$mock = $this->Auth->constructAuthorize();
		$mock[0]->expects($this->once())
			->method('mapActions')
			->with(array('create' => array('my_action')));

		$this->Auth->mapActions(array('create' => array('my_action')));
	}

/**
 * test logging in with a request.
 *
 * @return void
 */
	public function testLoginWithRequestData() {
		$this->getMock('Cake\Controller\Component\Auth\FormAuthenticate', array(), array(), 'RequestLoginMockAuthenticate', false);
		class_alias('RequestLoginMockAuthenticate', 'Cake\Controller\Component\Auth\RequestLoginMockAuthenticate');
		$request = new Request('users/login');
		$user = array('username' => 'mark', 'role' => 'admin');

		$this->Auth->request = $request;
		$this->Auth->authenticate = array('RequestLoginMock');
		$mock = $this->Auth->constructAuthenticate();
		$mock[0]->expects($this->once())
			->method('authenticate')
			->with($request)
			->will($this->returnValue($user));

		$this->assertTrue($this->Auth->login());
		$this->assertEquals($user['username'], $this->Auth->user('username'));
	}

/**
 * test login() with user data
 *
 * @return void
 */
	public function testLoginWithUserData() {
		$this->assertFalse((bool)$this->Auth->user());

		$user = array(
			'username' => 'mariano',
			'password' => '$2a$10$u05j8FjsvLBNdfhBhc21LOuVMpzpabVXQ9OpC2wO3pSO0q6t7HHMO',
			'created' => new \DateTime('2007-03-17 01:16:23'),
			'updated' => new \DateTime('2007-03-17 01:18:31')
		);
		$this->assertTrue($this->Auth->login($user));
		$this->assertTrue((bool)$this->Auth->user());
		$this->assertEquals($user['username'], $this->Auth->user('username'));
	}

/**
 * test flash settings.
 *
 * @return void
 */
	public function testFlashSettings() {
		$this->Auth->Session = $this->getMock('Cake\Controller\Component\SessionComponent', array(), array(), '', false);
		$this->Auth->Session->expects($this->once())
			->method('setFlash')
			->with('Auth failure', 'custom', array(1), 'auth-key');

		$this->Auth->flash = array(
			'element' => 'custom',
			'params' => array(1),
			'key' => 'auth-key'
		);
		$this->Auth->flash('Auth failure');
	}

/**
 * test the various states of Auth::redirect()
 *
 * @return void
 */
	public function testRedirectSet() {
		$value = array('controller' => 'users', 'action' => 'home');
		$result = $this->Auth->redirectUrl($value);
		$this->assertEquals('/users/home', $result);
		$this->assertEquals($value, $this->Auth->Session->read('Auth.redirect'));
	}

/**
 * test redirect using Auth.redirect from the session.
 *
 * @return void
 */
	public function testRedirectSessionRead() {
		$this->Auth->loginAction = array('controller' => 'users', 'action' => 'login');
		$this->Auth->Session->write('Auth.redirect', '/users/home');

		$result = $this->Auth->redirectUrl();
		$this->assertEquals('/users/home', $result);
		$this->assertFalse($this->Auth->Session->check('Auth.redirect'));
	}

/**
 * test redirectUrl with duplicate base.
 *
 * @return void
 */
	public function testRedirectSessionReadDuplicateBase() {
		$this->Auth->request->webroot = '/waves/';
		$this->Auth->request->base = '/waves';

		Router::setRequestInfo($this->Auth->request);

		$this->Auth->Session->write('Auth.redirect', '/waves/add');

		$result = $this->Auth->redirectUrl();
		$this->assertEquals('/waves/add', $result);
	}

/**
 * test that redirect does not return loginAction if that is what's stored in Auth.redirect.
 * instead loginRedirect should be used.
 *
 * @return void
 */
	public function testRedirectSessionReadEqualToLoginAction() {
		$this->Auth->loginAction = array('controller' => 'users', 'action' => 'login');
		$this->Auth->loginRedirect = array('controller' => 'users', 'action' => 'home');
		$this->Auth->Session->write('Auth.redirect', array('controller' => 'users', 'action' => 'login'));

		$result = $this->Auth->redirectUrl();
		$this->assertEquals('/users/home', $result);
		$this->assertFalse($this->Auth->Session->check('Auth.redirect'));
	}

/**
 * test that the returned URL doesn't contain the base URL.
 *
 * @see https://cakephp.lighthouseapp.com/projects/42648/tickets/3922-authcomponentredirecturl-prepends-appbaseurl
 *
 * @return void This test method doesn't return anything.
 */
	public function testRedirectUrlWithBaseSet() {
		$App = Configure::read('App');

		Configure::write('App', array(
			'dir' => APP_DIR,
			'webroot' => WEBROOT_DIR,
			'base' => false,
			'baseUrl' => '/cake/index.php'
		));

		$url = '/users/login';
		$this->Auth->request = $this->Controller->request = new Request($url);
		$this->Auth->request->addParams(Router::parse($url));
		$this->Auth->request->url = Router::normalize($url);

		Router::setRequestInfo($this->Auth->request);

		$this->Auth->loginAction = array('controller' => 'users', 'action' => 'login');
		$this->Auth->loginRedirect = array('controller' => 'users', 'action' => 'home');

		$result = $this->Auth->redirectUrl();
		$this->assertEquals('/users/home', $result);
		$this->assertFalse($this->Auth->Session->check('Auth.redirect'));

		Configure::write('App', $App);
		Router::reload();
	}

/**
 * testUser method
 *
 * @return void
 */
	public function testUser() {
		$data = array(
			'User' => array(
				'id' => '2',
				'username' => 'mark',
				'group_id' => 1,
				'Group' => array(
					'id' => '1',
					'name' => 'Members'
				),
				'is_admin' => false,
		));
		$this->Auth->Session->write('Auth', $data);

		$result = $this->Auth->user();
		$this->assertEquals($data['User'], $result);

		$result = $this->Auth->user('username');
		$this->assertEquals($data['User']['username'], $result);

		$result = $this->Auth->user('Group.name');
		$this->assertEquals($data['User']['Group']['name'], $result);

		$result = $this->Auth->user('invalid');
		$this->assertEquals(null, $result);

		$result = $this->Auth->user('Company.invalid');
		$this->assertEquals(null, $result);

		$result = $this->Auth->user('is_admin');
		$this->assertFalse($result);
	}

/**
 * testStatelessAuthNoRedirect method
 *
 * @expectedException Cake\Error\UnauthorizedException
 * @expectedExceptionCode 401
 * @return void
 */
	public function testStatelessAuthNoRedirect() {
		if (Session::id()) {
			session_destroy();
			Session::$id = null;
		}
		$event = new Event('Controller.startup', $this->Controller);
		$_SESSION = null;

		AuthComponent::$sessionKey = false;
		$this->Auth->authenticate = array('Basic');
		$this->Controller->request['action'] = 'admin_add';

		$result = $this->Auth->startup($event);
	}

/**
 * testStatelessAuthNoSessionStart method
 *
 * @return void
 */
	public function testStatelessAuthNoSessionStart() {
		if (Session::id()) {
			session_destroy();
			Session::$id = null;
		}
		$event = new Event('Controller.startup', $this->Controller);

		AuthComponent::$sessionKey = false;
		$this->Auth->authenticate = array(
			'Basic' => array('userModel' => 'AuthUsers')
		);
		$this->Controller->request['action'] = 'admin_add';

		$this->Controller->request->env('PHP_AUTH_USER', 'mariano');
		$this->Controller->request->env('PHP_AUTH_PW', 'cake');

		$result = $this->Auth->startup($event);
		$this->assertTrue($result);

		$this->assertNull(Session::id());
	}

/**
 * testStatelessAuthRedirect method
 *
 * @return void
 */
	public function testStatelessFollowedByStatefulAuth() {
		$event = new Event('Controller.startup', $this->Controller);
		$this->Auth->authenticate = array('Basic', 'Form');
		$this->Controller->request['action'] = 'admin_add';

		$this->Auth->response->expects($this->never())->method('statusCode');
		$this->Auth->response->expects($this->never())->method('send');

		$result = $this->Auth->startup($event);
		$this->assertFalse($result);

		$this->assertEquals('/users/login', $this->Controller->testUrl);
	}
}
