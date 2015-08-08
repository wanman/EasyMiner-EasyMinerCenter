<?php

namespace EasyMinerCenter;

use Drahak\Restful\Application\IResourceRouter;
use Drahak\Restful\Application\Routes\CrudRoute;
use Nette, Nette\Application\Routers\RouteList, Nette\Application\Routers\Route;


/**
 * Router factory.
 */
class RouterFactory {
  const REST_MODULE_BASE_URL = 'api/';
  const INSTALL_MODULE_BASE_URL = 'install/';

	/**
   * @return \Nette\Application\IRouter
   */
	public function createRouter() {
    $router = new RouteList();
    $router[] = new Route('', ['module' => 'EasyMiner', 'presenter' => 'Homepage', 'action' => 'default']);

    $router[] = $dataMiningRouter = new RouteList('EasyMiner');
    $dataMiningRouter[] = new Route('em/user/oauth-[!<type=google>]', ['presenter' => 'User', 'action' => 'oauthGoogle', null => array(Route::FILTER_IN => function (array $params) {
      $params['do'] = $params['type'] . 'Login-response';
      unset($params['type']);

      return $params;
    }, Route::FILTER_OUT => function (array $params) {
      if (empty($params['do']) || !preg_match('~^login\\-([^-]+)\\-response$~', $params['do'], $m)) {
        return null;
      }

      $params['type'] = \Nette\Utils\Strings::lower($m[1]);
      unset($params['do']);

      return $params;
    },),]);
    $dataMiningRouter[] = new Route('em/<presenter>[/<action=default>[/<id>]]');

    #region router pro RestModule
    $router[] = $restRouter = new RouteList('Rest');
    $restRouter[] = new Route(self::REST_MODULE_BASE_URL . 'auth[/<action=default>]', ['presenter' => 'Auth']);
    $restRouter[] = new CrudRoute(self::REST_MODULE_BASE_URL . '<presenter>[/<id>[/<relation>[/<relationId>]]]', [], IResourceRouter::GET | IResourceRouter::POST | IResourceRouter::PUT | IResourceRouter::DELETE);
    $restRouter[] = new Route(self::REST_MODULE_BASE_URL, ['presenter' => 'Homepage', 'action' => 'read']);
    #endregion

    $router[] = $installRouter = new RouteList('Install');
    $installRouter[] = new Route(self::INSTALL_MODULE_BASE_URL.'<presenter>/<action>', 'Default:default');

    //$router[] = new Route('<presenter>/<action>[/<id>]', 'Homepage:default');

    return $router;
  }

}
