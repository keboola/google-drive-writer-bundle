<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 13/02/15
 * Time: 15:43
 */
namespace Keboola\Google\DriveWriterBundle\Tests\Writer;

use Keboola\Google\DriveWriterBundle\Writer\Writer;
use Syrup\ComponentBundle\Test\WebTestCase;

class WriterTest extends WebTestCase
{
    /** @var Writer */
    protected $writer;

    protected $httpClient;

    public function setUp()
    {
        $this->httpClient = $this->createClient();

        $container = $this->httpClient->getContainer();

        $encryptor = $container->get('syrup.encryptor');
        $accessToken = $encryptor->decrypt(ACCESS_TOKEN);
        $refreshToken = $encryptor->decrypt(REFRESH_TOKEN);

        $restApi = $container->get('wr_google_drive.rest_api');
        $restApi->getApi()->setCredentials($accessToken, $refreshToken);
        $restApi->getApi()->setRefreshTokenCallback(array($this, 'refreshTokenCallback'));

        $this->writer = $container->get('wr_google_drive.writer');

//        $this->testCsvPath = realpath(__DIR__ . '/../data/test.csv');
//        $this->test2CsvPath = realpath(__DIR__ . '/../data/test2.csv');
//        $this->test3CsvPath = realpath(__DIR__ . '/../data/test3.csv');
//        $this->expectedFeedPath = realpath(__DIR__ . '/../data/test-feed.xml');

    }

    /** Create new File */
    public function testCreateFile()
    {

    }

    /** Create new Sheet */
    public function testCreateSheet()
    {

    }

    /** Update existing file */
    public function testUpdateFile()
    {

    }

    /** Update existing sheet */
    public function testUpdateSheet()
    {

    }

    /** @TOOD append existing sheet */
    public function testAppendSheet()
    {

    }


}
