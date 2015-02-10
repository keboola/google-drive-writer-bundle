<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/04/14
 * Time: 17:16
 */

namespace Keboola\Google\DriveWriterBundle\Tests;

use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Symfony\Component\HttpFoundation\Response;
use Syrup\ComponentBundle\Encryption\Encryptor;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Syrup\ComponentBundle\Test\AbstractFunctionalTest;

class FunctionalTest extends AbstractFunctionalTest
{
	/** @var Configuration */
	protected $configuration;

	/** @var Encryptor */
	protected $encryptor;

	protected $componentName = 'wr-google-drive';

	protected $accountId = 'test';
	protected $accountName = 'Test';
	protected $googleId = '123456';
	protected $googleName = 'googleTestAccount';
	protected $email = 'test@keboola.com';
	protected $accessToken = 'accessToken';
	protected $refreshToken = 'refreshToken';

	protected $fileGoogleId;
	protected $fileTitle;
	protected $fileType;
	protected $sheetId;
	protected $tableId;

	protected function setUp()
	{
		parent::setUp();

		$container = $this->httpClient->getContainer();

		$this->encryptor = $container->get('syrup.encryptor');

		$this->configuration = $container->get('wr_google_drive.configuration');
		$this->configuration->setStorageApi($this->storageApiClient);

		try {
			$this->configuration->create();
		} catch (\Exception $e) {
			// bucket exists
		}

		// Cleanup
		$sysBucketId = $this->configuration->getSysBucketId();
		$accTables = $this->storageApiClient->listTables($sysBucketId);
		foreach ($accTables as $table) {
			$this->storageApiClient->dropTable($table['id']);
		}

		$this->initEnv();
	}

	protected function initEnv()
	{
		$this->googleId = GOOGLE_ID;
		$this->googleName = GOOGLE_NAME;
		$this->email = EMAIL;
		$this->accessToken = $this->encryptor->decrypt(ACCESS_TOKEN);
		$this->refreshToken = $this->encryptor->decrypt(REFRESH_TOKEN);
		$this->fileTitle = FILE_TITLE;
		$this->fileGoogleId = FILE_GOOGLE_ID;
		$this->fileType = FILE_TYPE;
		$this->sheetId = SHEET_ID;
		$this->tableId = TABLE_ID;
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
		$this->httpClient->request(
			'POST',
			$this->componentName . '/configs',
			[],
			[],
			[],
			json_encode([
				'name' => 'Test',
				'description' => 'Test Account created by PhpUnit test suite'
			])
		);

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response['id']);
		$this->assertEquals('Test', $response['name']);
	}

	public function testGetConfig()
	{
		$this->createConfig();

		$this->httpClient->request('GET', $this->componentName . '/configs');

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertEquals('test', $response[0]['id']);
		$this->assertEquals('Test', $response[0]['name']);
	}

	public function testDeleteConfig()
	{
		$this->createConfig();

		$this->httpClient->request('DELETE', $this->componentName . '/configs/test');

		/* @var Response $response */
		$response = $this->httpClient->getResponse();

		$accounts = $this->configuration->getAccounts(true);

		$this->assertEquals(204, $response->getStatusCode());
		$this->assertEmpty($accounts);
	}

	/**
	 * Accounts
	 */

	public function testGetAccount()
	{
		$this->createConfig();
		$this->createAccount();

		$this->httpClient->request('GET', $this->componentName . '/accounts/' . $this->accountId);

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$account = $this->configuration->getAccountBy('accountId', $response['accountId']);

		$this->assertAccount($account);
	}

	public function testGetAccounts()
	{
		$this->createConfig();
		$this->createAccount();

		$this->httpClient->request(
			'GET', $this->componentName . '/accounts'
		);

		$responseJson = $this->httpClient->getResponse()->getContent();
		$response = json_decode($responseJson, true);

		$this->assertNotEmpty($response);

		$accountArr = array_shift($response);

		$this->assertAccount($this->configuration->getAccountBy('accountId', $accountArr['accountId']));
	}

	/**
	 * Files
	 */

	public function testPostFiles()
	{
		$this->createConfig();
		$this->createAccount();

		$this->httpClient->request(
			'POST',
			$this->componentName . '/files/' . $this->accountId,
			[],
			[],
			[],
			json_encode([
				[
					'tableId' => $this->tableId,
					'title' => $this->fileTitle,
				]
			])
		);

		$response = $this->httpClient->getResponse();

		$this->assertEquals(201, $response->getStatusCode());

		$account = $this->configuration->getAccount($this->accountId);
		$files = $account->getFiles();

		$this->assertNotEmpty($files);
		$file = array_shift($files);

		$this->assertEquals($this->fileTitle, $file->getTitle());
		$this->assertEquals($this->fileType, $file->getType());
		$this->assertEquals($this->tableId, $file->getTableId());
	}

	/**
	 * External
	 */

	public function testExternalLink()
	{
		$this->createConfig();
		$this->createAccount();

		$referrerUrl = $this->httpClient
			->getContainer()
			->get('router')
			->generate('keboola_google_drive_post_external_auth_link', array(), true);

		$this->httpClient->followRedirects();
		$this->httpClient->request(
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

		$responseJson = $this->httpClient->getResponse()->getContent();
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
