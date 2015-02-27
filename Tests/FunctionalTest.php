<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/04/14
 * Time: 17:16
 */

namespace Keboola\Google\DriveWriterBundle\Tests;

use Keboola\Csv\CsvFile;
use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Keboola\StorageApi\Client;
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

	protected $fileTitle = 'Google Drive TEST';
	protected $tableId = 'in.c-wr-google-drive.test';

	protected $testCsvPath;

	/** @var RestApi */
	protected $restApi;

	protected function setUp()
	{
		parent::setUp();

		$container = $this->httpClient->getContainer();

		$this->encryptor = $container->get('syrup.encryptor');
		$this->restApi = $container->get('wr_google_drive.rest_api');

		$this->testCsvPath = __DIR__ . '/data/test.csv';

		$this->configuration = $container->get('wr_google_drive.configuration');
		$this->configuration->setStorageApi($this->storageApiClient);

		try {
			$this->configuration->create();
		} catch (\Exception $e) {
			// bucket exists
		}

		$this->initEnv();
		$this->initApi($this->accessToken, $this->refreshToken);

		// Cleanup
		$sysBucketId = $this->configuration->getSysBucketId();
		$accTables = $this->storageApiClient->listTables($sysBucketId);
		foreach ($accTables as $table) {
			$this->storageApiClient->dropTable($table['id']);
		}

		if ($this->storageApiClient->tableExists($this->tableId)) {
			$this->storageApiClient->dropTable($this->tableId);
		}

		if (!$this->storageApiClient->bucketExists('in.c-wr-google-drive')) {
			$this->storageApiClient->createBucket('wr-google-drive', Client::STAGE_IN, 'Google Drive IN bucket');
		}

		$csvFile = new CsvFile($this->testCsvPath);
		$this->storageApiClient->createTable('in.c-wr-google-drive', 'test', $csvFile);
	}

	protected function initEnv()
	{
		$this->googleId = GOOGLE_ID;
		$this->googleName = GOOGLE_NAME;
		$this->email = EMAIL;
		$this->accessToken = $this->encryptor->decrypt(ACCESS_TOKEN);
		$this->refreshToken = $this->encryptor->decrypt(REFRESH_TOKEN);
		$this->tableId = TABLE_ID;
	}

	protected function initApi($accessToken, $refreshToken)
	{
		$this->restApi->getApi()->setCredentials($accessToken, $refreshToken);
		$this->restApi->getApi()->setRefreshTokenCallback(array($this, 'refreshTokenCallback'));
	}

	public function refreshTokenCallback($accessToken, $refreshToken)
	{
		$account = $this->configuration->getAccount($this->accountId);
		$account->setAccessToken($accessToken);
		$account->setRefreshToken($refreshToken);
		$account->save();
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
                'tableId' => $this->tableId,
                'title' => $this->fileTitle,
                'type' => 'file'
            ])
		);

		$response = $this->httpClient->getResponse();

		$this->assertEquals(201, $response->getStatusCode());

		$account = $this->configuration->getAccount($this->accountId);
		$files = $account->getFiles();

		$this->assertNotEmpty($files);
		$file = array_shift($files);

		$this->assertEquals($this->fileTitle, $file->getTitle());
		$this->assertEquals('file', $file->getType());
		$this->assertEquals($this->tableId, $file->getTableId());
	}

    public function testPutFiles()
    {
        $this->createConfig();
        $this->createAccount();

        // add file to config
        $fileId = $this->configuration->addFile($this->accountId, [
            'title' => 'Test Sheet',
            'tableId' => 'empty',
            'type' => 'sheet',
            'pathname' => $this->testCsvPath
        ]);

        // call the API
        $this->httpClient->request(
            'PUT',
            $this->componentName . '/files/' . $this->accountId . '/' . $fileId,
            [],
            [],
            [],
            json_encode([
                'title' => 'Test Sheet Update',
                'tableId' => 'updated'
            ])
        );

        $response = $this->httpClient->getResponse();

        $resFile = array_shift(json_decode($response->getContent(), true));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Test Sheet Update', $resFile['title']);
        $this->assertEquals('updated', $resFile['tableId']);
    }

	public function testGetFiles()
	{
		$this->createConfig();
		$this->createAccount();

		// add files to config
		$file = new File([
			'id' => 0,
			'title' => 'Test Sheet',
			'tableId' => 'empty',
			'type' => 'sheet',
			'pathname' => $this->testCsvPath
		]);

		$file2 = new File([
			'id' => 1,
			'title' => 'Test File',
			'tableId' => 'empty2',
			'type' => 'file',
			'pathname' => $this->testCsvPath
		]);

		$this->configuration->addFile($this->accountId, $file->toArray());
        $this->configuration->addFile($this->accountId, $file2->toArray());

		// call the API
		$this->httpClient->request(
			'GET',
			$this->componentName . '/files/' . $this->accountId
		);

		$response = $this->httpClient->getResponse();

		$this->assertEquals(200, $response->getStatusCode());

		$files = json_decode($response->getContent(), true);

		$this->assertCount(2, $files);

		$fileInConfig = array_shift($files);

		$this->assertEquals('Test Sheet', $fileInConfig['title']);
		$this->assertEquals('empty', $fileInConfig['tableId']);
		$this->assertEquals('sheet', $fileInConfig['type']);
        $this->assertEquals(File::OPERATION_UPDATE, $fileInConfig['operation']);
        $this->assertArrayHasKey('targetFolder', $fileInConfig);
        $this->assertArrayHasKey('sheetId', $fileInConfig);

		$fileInConfig = array_shift($files);

		$this->assertEquals('Test File', $fileInConfig['title']);
		$this->assertEquals('empty2', $fileInConfig['tableId']);
		$this->assertEquals('file', $fileInConfig['type']);
        $this->assertEquals(File::OPERATION_UPDATE, $fileInConfig['operation']);
        $this->assertArrayHasKey('targetFolder', $fileInConfig);
        $this->assertArrayHasKey('sheetId', $fileInConfig);
	}

	public function testDeleteFile()
	{
		$this->createConfig();
		$this->createAccount();

		// add files to config
		$this->configuration->addFile($this->accountId, [
            'title' => 'Test Sheet',
            'tableId' => 'empty'
		]);

		$files = $this->configuration->getFiles($this->accountId);

		/** @var File $file */
		$file = array_shift($files);

		// call the API
		$this->httpClient->request(
			'DELETE',
			$this->componentName . '/files/' . $this->accountId . '/' . $file->getId()
		);

		$response = $this->httpClient->getResponse();

		$this->assertEquals(204, $response->getStatusCode());

		$filesInConfig = $this->configuration->getFiles($this->accountId);

		$this->assertEmpty($filesInConfig);
	}

    public function testGetRemoteFile()
    {
        //@todo
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
			->generate('keboola_google_drive_writer_post_external_auth_link', [], true);

		$this->httpClient->followRedirects();
		$this->httpClient->request(
			'POST',
			$this->componentName . '/external-link',
			[],
			[],
			[],
			json_encode([
				'account'   => $this->accountId,
				'referrer'  => $referrerUrl
			])
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
        $this->createConfig();
        $this->createAccount();

        // add files to config
        $file = new File([
            'id' => 0,
            'title' => 'Test Sheet',
            'tableId' => $this->tableId,
            'type' => 'sheet'
        ]);

        $file2 = new File([
            'id' => 1,
            'title' => 'Test File',
            'tableId' => $this->tableId,
            'type' => 'file'
        ]);

        $this->configuration->addFile($this->accountId, $file->toArray());
        $this->configuration->addFile($this->accountId, $file2->toArray());

        // run
        $job = $this->processJob($this->componentName . '/run');

        $this->assertEquals('success', $job->getStatus());
    }

}
