<?php
/**
 * Created by Miroslav Čillík <miro@keboola.com>
 * Date: 15/01/14
 * Time: 17:20
 */

namespace Keboola\Google\DriveWriterBundle\Controller;

use Keboola\Encryption\EncryptorInterface;
use Keboola\Google\DriveWriterBundle\Exception\ConfigurationException;
use Keboola\Google\DriveWriterBundle\Writer\Configuration;
use Keboola\StorageApi\Client as StorageApi;
use Keboola\Google\ClientBundle\Google\RestApi;
use Keboola\Google\DriveWriterBundle\Exception\ParameterMissingException;
use Keboola\StorageApi\ClientException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Keboola\Syrup\Controller\BaseController;
use Keboola\Syrup\Exception\SyrupComponentException;
use Keboola\Syrup\Exception\UserException;

class OauthController extends BaseController
{
	/**
	 * @var AttributeBag
	 */
	protected $sessionBag;

	protected $componentName = 'wr-google-drive';

	/**
	 * @return RestApi
	 */
	private function getGoogleApi()
	{
		return $this->container->get('google_rest_api');
	}

	public function externalAuthAction(Request $request)
	{
		// check token - if expired redirect to error page
		try {
			$sapi = new StorageApi(array(
				'token'     => $request->request->get('token'),
				'userAgent' => $this->componentName
			));
			$sapi->verifyToken();
		} catch (ClientException $e) {

			if ($e->getCode() == 401) {
				return $this->render('KeboolaGoogleDriveWriterBundle:Oauth:expired.html.twig');
			} else {
				throw $e;
			}
		}

		$request->request->set('token', $request->query->get('token'));
		$request->request->set('account', $request->query->get('account'));
		$request->request->set('referrer', $request->query->get('referrer'));

		return $this->forward('KeboolaGoogleDriveWriterBundle:Oauth:oauth');
	}

	public function externalAuthFinishAction()
	{
		return $this->render('KeboolaGoogleDriveWriterBundle:Oauth:finish.html.twig');
	}

	public function oauthCallbackAction()
	{
		$session = $this->get('session');

		$token = $session->get('token');
		$accountId = $session->get('account');
		$referrer = $session->get('referrer');
        $external = $session->get('external');

		if ($token == null) {
			throw new UserException("Your session expired, please try again");
		}

		$code = $this->get('request')->query->get('code');

		if (empty($code)) {
			throw new SyrupComponentException(400, 'Could not read from Google API');
		}

		$googleApi = $this->getGoogleApi();

		try {
			$storageApi = new StorageApi(array(
				'token'     => $token,
				'userAgent' => $this->componentName
			));

			/** @var EncryptorInterface $encryptor */
			$encryptor = $this->get('syrup.encryptor');

			$configuration = new Configuration($this->componentName, $encryptor);
			$configuration->setStorageApi($storageApi);

			$tokens = $googleApi->authorize($code, $this->container->get('router')->generate(
					'keboola_google_drive_writer_oauth_callback', array(), UrlGeneratorInterface::ABSOLUTE_URL)
			);

			$googleApi->setCredentials($tokens['access_token'], $tokens['refresh_token']);
			$userData = $googleApi->call(RestApi::USER_INFO_URL)->json();

			$account = $configuration->getAccountBy('accountId', $accountId);

			if (null == $account) {
				throw new ConfigurationException("Account doesn't exist");
			}

			$account
				->setGoogleId($userData['id'])
				->setGoogleName($userData['name'])
				->setEmail($userData['email'])
				->setAccessToken($tokens['access_token'])
				->setRefreshToken($tokens['refresh_token']);
			$account->save();

			$this->container->get('session')->clear();

			if ($referrer) {

                if ($external) {
                    $referrer .= '?access-token=' . $account->getAttribute('accessToken')
                        . '&refresh-token=' . $account->getAttribute('refreshToken');
                }

				return new RedirectResponse($referrer);
			} else {
				return new JsonResponse(array('status' => 'ok'));
			}
		} catch (\Exception $e) {
			throw new SyrupComponentException(500, 'Could not save API tokens', $e);
		}
	}

	public function oauthAction(Request $request)
	{
		if (!$request->request->get('account')) {
			throw new ParameterMissingException("Parameter 'account' is missing");
		}

		$session = $this->get('session');
		$googleApi = $this->getGoogleApi();

		try {
			$client = new StorageApi(array(
				'token'     => $request->request->get('token'),
				'userAgent' => $this->componentName
			));

			$url = $googleApi->getAuthorizationUrl(
				$this->container->get('router')->generate('keboola_google_drive_writer_oauth_callback', array(), UrlGeneratorInterface::ABSOLUTE_URL),
				'https://www.googleapis.com/auth/drive https://www.googleapis.com/auth/userinfo.profile https://www.googleapis.com/auth/userinfo.email https://spreadsheets.google.com/feeds',
				'force'
			);

			$session->set('token', $client->getTokenString());
			$session->set('account', $request->request->get('account'));
			$session->set('referrer', $request->request->get('referrer'));
            $session->set('external', $request->request->get('external'));

			return new RedirectResponse($url);
		} catch (\Exception $e) {
			throw new SyrupComponentException(500, 'OAuth UI request error', $e);
		}
	}

}
