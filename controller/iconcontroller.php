<?php
/**
 * Nextcloud - passman
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Sander Brand <brantje@gmail.com>
 * @copyright Sander Brand 2016
 */

namespace OCA\Passman\Controller;

use OC\App\AppManager;
use OCA\Passman\Service\IconService;
use OCA\Passman\Utility\Utils;
use OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\IRequest;
use OCP\IURLGenerator;

class IconController extends ApiController
{
    private $am;
    private $urlGenerator;
    /**
     * @var ICache
     */
    private $cache;

    public function __construct($AppName,
                                IRequest $request,
                                ICache $cache,
                                AppManager $am,
                                IURLGenerator $urlGenerator
    )
    {
        parent::__construct(
            $AppName,
            $request,
            'GET, POST, DELETE, PUT, PATCH, OPTIONS',
            'Authorization, Content-Type, Accept',
            86400);

        $this->cache = $cache;
        $this->am = $am;
        $this->urlGenerator = $urlGenerator;

    }

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getSingleIcon($base64Url) {
        $icon = $this->getRemoteIcon($base64Url);

		if ($icon->icoExists) {
			$icon_json['type']= $icon->icoType;
			$icon_json['content']= base64_encode($icon->icoData);
			return new JSONResponse($icon_json);
		}

		return new JSONResponse();
	}

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function getIcon($base64Url)
    {
        $data = $this->retrieveIcon($base64Url);

        $offset = 3600 * 24 * 30;
        $contentType = 'image/png';
        $response = new DataDownloadResponse($data, 'icon', $contentType);

        $response->addHeader('Content-Type', $contentType);
        $response->addHeader('Content-Length:', mb_strlen($data));
        $response->addHeader('Expires: ', gmdate("D, d M Y H:i:s", time() + $offset) . " GMT");
        $response->setETag($base64Url);
        $response->cacheFor($offset);
        $response->addHeader('Cache-Control', 'max-age=' . $offset);

        return $response;
    }

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function getLocalIconList() {
		$dir = $this->am->getAppPath('passman');
		$result = Utils::getDirContents($dir . '/img/icons');

		$icons = [];
		foreach ($result as $icon) {
			$iconPath = $icon;
			$path = explode('passman/', $iconPath);
			$pack = explode('/', $path[1])[2];
			$mime = mime_content_type($iconPath);
			//print_r($path);
			if($mime !== 'directory') {
				$icon = [];
				$icon['mimetype'] = mime_content_type($iconPath);
				$icon['url'] = $this->urlGenerator->linkTo('passman', $path[1]);
				$icon['pack'] = $pack;
				if(!isset($icons[$pack])){
					$icons[$pack] = [];
				}
				$icons[$pack][] = $icon;
			}
		}
		return new JSONResponse($icons);
	}

    private function retrieveIcon($base64Url)
    {
        $dataEncoded = 'iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAMAAACdt4HsAAABHVBMVEUAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADF3oJhAAAAXnRSTlMAAQIDBAUGBwgJCwwOEBITFBUWFxgaHB4hJCUnKissMDI0ODs9PkFCQ0RNUVJWV1lbXF1hY2Zna2xtcXh7f4KDhYmUm52lq62vsLW3ucHFyszO0dPV197i7/H3+fv9358zuQAAAWdJREFUWMPtldlWwjAURdPWogyKOKM4z0NRQRRHnAdE0QoI1eb/P8OnmzYlSZs+unIes+/ZbdOuFCFuBmc2Dk+qpe18EsVIptTGJJ3jrGR99B4H8jQlUTfOMSM3ZtT+SAsz8z0ZrZ//wZy4S1H6C1iQtfD+tCsS4EJYP9kV9rGTCRE0fMOfxZypITO7++5b/NCE/S3fx7PsLc9/eeuWqK/3vA9ngAJ3BPwmBIIdMnYbvNNLgo4Egg4MvelBpD0D6/F3YYJcJd0PEw7AWa6gCCNnLLoPtMoVPMJIikVNoE2uAN6BzcZ1MPA2wRA+AUIHwHkn1BAM7LH5OvBhjiAFA6tsXgCe4wjSMLDC5nPAx5Xg3wrGylfk1GlcM/MC/KFW6fvRVbBkLuj+omwf401KUJcXtCiBIy+gT4UYfawrgRIogRIogRLwBG4MAfVnsuX7XX8fWfKCU0qgvcr2mwaiDZYtsw/tMtnCP4F4Y01BhTeiAAAAAElFTkSuQmCC';
        $host = $this->getHostFromBase64($base64Url);
        if (false === $host) {
            return base64_decode($dataEncoded);
        }

        $imageCached = $this->getCachedIcon($host);
        if ($imageCached) {
            return base64_decode($imageCached);
        }

        try {
            $icon = $this->getRemoteIcon($host);

            if ($icon->icoExists) {
                $this->cacheIcon($host, $icon->icoData);
                return $this->getCachedIcon($host);
            }
        } catch (\Exception $e) {
            $icon = null;
        }

        return base64_decode($dataEncoded);
    }

    /**
     * @param $host
     * @return IconService
     */
    private function getRemoteIcon($host)
    {
        if (!preg_match("~^(?:f|ht)tps?://~i", $host)) {
            $host = "http://" . $host;
        }
        return new IconService($host);
    }

    private function getCachedIcon($host)
    {
        $data = $this->cache->get($this->getKeyCache($host));
        if ($data) {
            return base64_decode($data);
        }
        return $data;
    }

    private function cacheIcon($host, $data)
    {
        return $this->cache->set($this->getKeyCache($host), base64_encode($data), 3600 * 24 * 365);
    }

    private function getKeyCache($host)
    {
        return 'passman.' . md5($host);
    }

    private function getUrlFromBase64($base64Url)
    {
        $url = base64_decode(str_replace('_', '/', $base64Url));
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "http://" . $url;
        }
        return $url;
    }

    private function getHostFromBase64($base64Url)
    {
        $host = parse_url($this->getUrlFromBase64($base64Url));
        if (false === $host || empty($host['host'])) {
            return false;
        }
        $scheme = $host['scheme'] ?? 'http';

        return $scheme . '://' . $host['host'];
    }
}
