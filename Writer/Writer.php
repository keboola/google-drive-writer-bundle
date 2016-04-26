<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 29/01/15
 * Time: 16:30
 */

namespace Keboola\Google\DriveWriterBundle\Writer;

use GuzzleHttp\Exception\BadResponseException;
use Keboola\Google\DriveWriterBundle\Entity\Account;
use Keboola\Google\DriveWriterBundle\Entity\File;
use Keboola\Google\DriveWriterBundle\GoogleDrive\RestApi;
use Keboola\Google\DriveWriterBundle\Writer\Processor\FileProcessor;
use Keboola\Google\DriveWriterBundle\Writer\Processor\ProcessorInterface;
use Keboola\Google\DriveWriterBundle\Writer\Processor\SheetProcessor;
use Monolog\Logger;
use Keboola\Syrup\Exception\UserException;

class Writer
{
	/** @var RestApi */
	protected $googleDriveApi;

	/** @var Logger */
	protected $logger;

	/** @var Account */
	protected $currAccount;

    /** @var ProcessorInterface */
    protected $processor;

	public function __construct(RestApi $googleDriveApi, Logger $logger, Account $account)
	{
		$this->googleDriveApi = $googleDriveApi;
		$this->logger = $logger;
        $this->currAccount = $account;
        $this->initApi();
	}

    public function remoteFileExists(File $file)
    {
        if ($file->getGoogleId() == null) {
            return false;
        }

        try {
            $remoteFile = $this->getFile($file->getGoogleId());
            return !$remoteFile['labels']['trashed'];
        } catch (BadResponseException $e) {
            if ($e->getResponse()->getStatusCode() == 404) {
                return false;
            }
            throw $e;
        }
    }

    public function process(File $file)
    {
        try {
            if ($this->remoteFileExists($file)) {
                $file->setGoogleId(null);
                $file->setSheetId(null);
            }
            $this->processor = $this->getProcessor($file->getType());
            return $this->processor->process($file);
        } catch (BadResponseException $e) {
            throw new UserException($e->getMessage(), $e, [
                'file' => $file->toArray()
            ]);
        }
    }

	public function listFiles($params = [])
	{
		return $this->googleDriveApi->listFiles($params);
	}

    public function getFile($fileGoogleId)
    {
        return $this->googleDriveApi->getFile($fileGoogleId);
    }

	public function refreshToken()
	{
		$response = $this->googleDriveApi->getApi()->refreshToken();
		return $response['access_token'];
	}

	protected function initApi()
	{
		$this->googleDriveApi->getApi()->setCredentials($this->currAccount->getAccessToken(), $this->currAccount->getRefreshToken());
		$this->googleDriveApi->getApi()->setRefreshTokenCallback(array($this, 'refreshTokenCallback'));
	}

	public function refreshTokenCallback($accessToken, $refreshToken)
	{
		$account = $this->currAccount;
		$account->setAccessToken($accessToken);
		$account->setRefreshToken($refreshToken);
		$account->save();
	}

    protected function getProcessor($fileType)
    {
        switch ($fileType) {
            case File::TYPE_FILE:
                return new FileProcessor($this->googleDriveApi, $this->logger);
            case File::TYPE_SHEET:
                return new SheetProcessor($this->googleDriveApi, $this->logger);
            default:
                throw new UserException("Unknown file type");
        }
    }

}
