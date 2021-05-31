<?php

namespace Nordkirche\NkcBase\Service;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Nordkirche\Ndk\Api;
use Nordkirche\Ndk\Configuration;
use Nordkirche\Ndk\Domain\Query\PageQuery;
use Nordkirche\Ndk\Domain\Repository\AbstractRepository;
use Nordkirche\Ndk\Service\Result;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\VersionNumberUtility;

class ApiService
{

    /**
     * @var Api
     */
    protected static $NdkApi = null;

    /**
     * @return Api
     * @throws \Nordkirche\NkcBase\Exception\ApiException
     */
    public static function get()
    {
        if (self::$NdkApi === null) {
            self::$NdkApi = self::initializeApi();
        }
        return self::$NdkApi;
    }

    /**
     * @return Api
     * @throws \Nordkirche\NkcBase\Exception\ApiException
     */
    private static function initializeApi()
    {
        $EXT_CONF = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nkc_base'];

        if (self::checkConfiguration($EXT_CONF)) {
            try {
                $config = new Configuration(
                    $EXT_CONF['NDK_NAPI_USER_ID'],
                    $EXT_CONF['NDK_NAPI_ACCESS_TOKEN'],
                    $EXT_CONF['NDK_NAPI_HOST'],
                    $EXT_CONF['NDK_NAPI_PORT'],
                    $EXT_CONF['NDK_NAPI_PATH'],
                    $EXT_CONF['NDK_NAPI_PROTOCOL'],
                    $EXT_CONF['NDK_NAPI_VERSION'],
                    [$EXT_CONF['NDK_HTTP_AUTH_USERNAME'], $EXT_CONF['NDK_HTTP_AUTH_PASSWORD']]
                );

                if (isset($EXT_CONF['NDK_NAPI_TIMEOUT'])) {
                    $config->setRequestTimeout((int)($EXT_CONF['NDK_NAPI_TIMEOUT']));
                } else {
                    $config->setRequestTimeout(20);
                }

                if (Environment::getContext()->isDevelopment() && $EXT_CONF['NDK_LOG_FILE']) {
                    $monolog = new Logger('NdkRequestLogger');
                    $monolog->pushHandler(new StreamHandler($EXT_CONF['NDK_LOG_FILE']));
                    $config->setLogger($monolog);
                }

                if (isset($EXT_CONF['NDK_REDIS_CACHE']['host'], $EXT_CONF['NDK_REDIS_CACHE']['port'])) {
                    $redis = new \Redis();
                    $redis->connect($EXT_CONF['NDK_REDIS_CACHE']['host'], $EXT_CONF['NDK_REDIS_CACHE']['port']);

                    $cacheDriver = new \Doctrine\Common\Cache\RedisCache();
                    $cacheDriver->setRedis($redis);

                    $config->setReflectionCacheProvider(clone $cacheDriver);
                    $config->setHttpCacheProvider(clone $cacheDriver);
                    $config->setDependencyInjectionCacheProvider(clone $cacheDriver);
                }

                return new Api($config);
            } catch (\Exception $e) {
                throw new \Nordkirche\NkcBase\Exception\ApiException('Configuration error - please check API configuration', 1495105254);
            }
        } else {
            // Error : configuration missing
            throw new \Nordkirche\NkcBase\Exception\ApiException('Configuration error - please check API configuration', 1495105254);
        }
    }

    /**
     * @param array $EXT_CONF
     * @return bool
     */
    private static function checkConfiguration($EXT_CONF)
    {
        return ($EXT_CONF['NDK_NAPI_HOST'] != '') &&
            ($EXT_CONF['NDK_NAPI_PORT'] != '') &&
            ($EXT_CONF['NDK_NAPI_PATH'] != '') &&
            ($EXT_CONF['NDK_NAPI_PROTOCOL'] != '') &&
            ($EXT_CONF['NDK_NAPI_VERSION'] != '');
    }

    /**
     * @param string $object
     * @return \Nordkirche\Ndk\Service\FactoryService|\Nordkirche\Ndk\Domain\Repository\AbstractRepository
     * @throws \Nordkirche\NkcBase\Exception\ApiException
     */
    public static function getRepository($object)
    {
        $api = self::get();

        $classname = sprintf('\Nordkirche\Ndk\Domain\Repository\%sRepository', ucfirst($object));

        if (class_exists($classname)) {
            return $api->factory($classname);
        }
        throw new \Nordkirche\NkcBase\Exception\ApiException('Query error - unknown object ' . $object, 1495179007);
    }

    /**
     * @param $object
     * @return \Nordkirche\Ndk\Domain\Query\AbstractQuery
     * @throws \Nordkirche\NkcBase\Exception\ApiException
     */
    public static function getQuery($object)
    {
        $classname = sprintf('\Nordkirche\Ndk\Domain\Query\%sQuery', ucfirst($object));

        if (class_exists($classname)) {
            return  new $classname();
        }
        throw new \Nordkirche\NkcBase\Exception\ApiException('Configuration error - unknown object ' . $object, 1495179007);
    }

    /**
     * @param AbstractRepository $repository
     * @param PageQuery $query
     * @param array $includes
     * @param int $pageSize
     * @return array
     */
    public static function getAllItems($repository, $query, $includes = [], $pageSize = 50)
    {
        $resultArray = [];
        $currentPage = 1;

        $query->setPageSize($pageSize);

        if (count($includes)) {
            $query->setInclude($includes);
        }

        do {
            $query->setPageNumber($currentPage);
            /** @var Result $result */
            try {
                $result = $repository->get($query);
                foreach ($result as $item) {
                    $resultArray[] = $item;
                }
                $currentPage++;
            } catch (\Exception $e) {
                return [];
            }
        } while ($result->getPageCount() >= $currentPage);

        return $resultArray;
    }
}
