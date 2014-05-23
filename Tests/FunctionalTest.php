<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/04/14
 * Time: 17:16
 */

namespace Keboola\Google\DriveWriterBundle\Tests;

use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\StorageApi\Client as SapiClient;
use Symfony\Component\HttpFoundation\Response;
use Syrup\ComponentBundle\Test\WebTestCase;
use Symfony\Bundle\FrameworkBundle\Client;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Syrup\ComponentBundle\Service\Encryption\Encryptor;
use Syrup\ComponentBundle\Service\Encryption\EncryptorFactory;

class FunctionalTest extends WebTestCase
{
	/** @var SapiClient */
	protected $storageApi;

	/** @var Client */
	protected static $client;

	/** @var Encryptor */
	protected $encryptor;

	/** @var Configuration */
	protected $configuration;

	protected $componentName = 'wr-google-drive';

	protected $accountId = 'test';

	protected $accountName = 'Test';

	protected $googleId = '123456';

	protected $googleName = 'googleTestAccount';

	protected $email = 'test@keboola.com';

	protected $accessToken = 'accessToken';

	protected $refreshToken = 'refreshToken';

	protected function setUp()
	{
		self::$client = static::createClient();
		$container = self::$client->getContainer();

		$sapiToken = $container->getParameter('storage_api.test.token');
		$sapiUrl = $container->getParameter('storage_api.test.url');

		self::$client->setServerParameters(array(
			'HTTP_X-StorageApi-Token' => $sapiToken
		));

		$this->storageApi = new SapiClient($sapiToken, $sapiUrl, $this->componentName);

		/** @var EncryptorFactory $encryptorFactory */
		$encryptorFactory = $container->get('syrup.encryptor_factory');
		$this->encryptor = $encryptorFactory->get($this->componentName);

		$this->configuration = new Configuration($this->storageApi, $this->componentName, $this->encryptor);

		try {
			$this->configuration->create();
		} catch (\Exception $e) {
			// bucket exists
		}

		// Cleanup
		$sysBucketId = $this->configuration->getSysBucketId();
		$accTables = $this->storageApi->listTables($sysBucketId);
		foreach ($accTables as $table) {
			$this->storageApi->dropTable($table['id']);
		}
	}

	protected function createConfig()
	{
		$this->configuration->addAccount(array(
			'id'            => $this->accountId,
			'name'          => $this->accountName,
			'accountName'   => $this->accountName,
			'description'   => 'Test Account created by PhpUnit test suite'
		));
	}

	protected function createAccount()
	{
		$account = $this->configuration->getAccountBy('accountId', $this->accountId);
		$account->setAccountName($this->accountName);
		$account->setGoogleId($this->googleId);
		$account->setGoogleName($this->googleName);
		$account->setEmail($this->email);
		$account->setAccessToken($this->accessToken);
		$account->setRefreshToken($this->refreshToken);

		$account->save();
	}

	/**
	 * @param Account $account
	 */
	protected function assertAccount(Account $account)
	{
		$this->assertEquals($this->accountId, $account->getAccountId());
		$this->assertEquals($this->accountName, $account->getAccountName());
		$this->assertEquals($this->googleId, $account->getGoogleId());
		$this->assertEquals($this->googleName, $account->getGoogleName());
		$this->assertEquals($this->email, $account->getEmail());
		$this->assertEquals($this->accessToken, $account->getAccessToken());
		$this->assertEquals($this->refreshToken, $account->getRefreshToken());
	}

	/**
	 * Config
	 */

	public function testPostConfig()
	{
		self::$client->request(
			'POST', $this->componentName . '/configs',
			array(),
			array(),
			array(),
			json_encode(array(
				'name'          => 'Test',
				'description'   => 'Test Account created by PhpUnit test suite'
			))
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response['id']);
		$this->assertEquals('Test', $response['name']);
	}

	public function testGetConfig()
	{
		$this->createConfig();

		self::$client->request('GET', $this->componentName . '/configs');

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response[0]['id']);
		$this->assertEquals('Test', $response[0]['name']);
	}

	public function testDeleteConfig()
	{
		$this->createConfig();

		self::$client->request('DELETE', $this->componentName . '/configs/test');

		/* @var Response $response */
		$response = self::$client->getResponse();

		$accounts = $this->configuration->getAccounts(true);

		$this->assertEquals(204, $response->getStatusCode());
		$this->assertEmpty($accounts);
	}

	/**
	 * Accounts
	 */

	public function testPostAccount()
	{
		$this->createConfig();

		self::$client->request(
			'POST', $this->componentName . '/account',
			array(),
			array(),
			array(),
			json_encode(array(
				'id'            => $this->accountId,
				'googleId'      => $this->googleId,
				'googleName'    => $this->googleName,
				'email'         => $this->email,
				'accessToken'   => $this->accessToken,
				'refreshToken'  => $this->refreshToken
			))
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response['id']);

		$accounts = $this->configuration->getAccounts();
		$account = $accounts['test'];

		$this->assertAccount($account);
	}

	public function testGetAccount()
	{
		$this->createConfig();
		$this->createAccount();

		self::$client->request('GET', $this->componentName . '/account/' . $this->accountId);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$account = $this->configuration->getAccountBy('accountId', $response['accountId']);

		$this->assertAccount($account);
	}

	public function testGetAccounts()
	{
		$this->createConfig();
		$this->createAccount();

		self::$client->request(
			'GET', $this->componentName . '/accounts'
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertNotEmpty($response);

		$accountArr = array_shift($response);

		$this->assertAccount($this->configuration->getAccountBy('accountId', $accountArr['accountId']));
	}

	public function testPostSheets()
	{
		$this->createConfig();
		$this->createAccount();

		self::$client->request(
			'POST', $this->componentName . '/sheets/' . $this->accountId,
			array(),
			array(),
			array(),
			json_encode(array(
				'data'  => array(
					array(
						'googleId'  => $this->fileGoogleId,
						'title'     => $this->fileTitle,
						'sheetId'   => $this->sheetId,
						'sheetTitle'    => $this->sheetTitle
					)
				)
			))
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('ok', $response['status']);

		$account = $this->configuration->getAccountBy('accountId', $this->accountId);
		$sheets = $account->getSheets();

		$this->assertNotEmpty($sheets);

		$this->assertSheet(array_shift($sheets));
	}

	/**
	 * External
	 */

	public function testExternalLink()
	{
		$this->createConfig();
		$this->createAccount();

		$referrerUrl = self::$client
			->getContainer()
			->get('router')
			->generate('keboola_google_drive_post_external_auth_link', array(), true);

		self::$client->followRedirects();
		self::$client->request(
			'POST',
			$this->componentName . '/external-link',
			array(),
			array(),
			array(),
			json_encode(array(
				'account'   => $this->accountId,
				'referrer'  => $referrerUrl
			))
		);

		$responseJson = self::$client->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertArrayHasKey('link', $response);
		$this->assertNotEmpty($response['link']);
	}

	/**
	 * Run
	 */

	public function testRun()
	{
		//@TODO
	}
}
