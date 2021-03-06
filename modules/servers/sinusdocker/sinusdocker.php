﻿<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}


require_once(__DIR__ . '/lib/shipyard.php');
require_once(__DIR__ . '/lib/database.php');

use sinusdocker\helpers\database as DBhelper;

loadLang();

/**
 * Основные данные о модуле
 *
 *
 *
 * @see http://docs.whmcs.com/Provisioning_Module_Meta_Data_Parameters
 *
 * @return array
 */
function sinusdocker_MetaData() {
    return array(
        'DisplayName' => 'SinusBot docker',
        'APIVersion' => '1.1', // Use API Version 1.1
        'RequiresServer' => true, // Set true if module requires a server to work
        'DefaultNonSSLPort' => '8080', // Default Non-SSL Connection Port
    );
}

/**
 * Дополнительные поля для конфигурации продукта
 *
 *
 * Максимум 24 параметра, поддерживаются следующие типы:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * 
 *
 * @return array
 */
function sinusdocker_ConfigOptions() {
    global $_LANG;
    return array(
        'images' => array(
            'Type' => 'text',
            'Size' => '120',
            'Default' => '07artem132/sinusbot:0.9.16-10f0fad',
            'Description' => '<br>' . sprintf($_LANG['sinusdocker_maximum_characters'], 120),
        ), 'HTTP proto' => array(
            'Type' => 'dropdown',
            'Options' => array(
                'http' => 'http',
                'https' => 'https',
            ),
            'Description' => '<br>' . $_LANG['sinusdocker_prefix_to_the_URL_bot'],
        ), $_LANG['sinusdocker_prefix_container'] => array(
            'Type' => 'text',
            'Size' => '30',
            'Default' => 'billing_',
            'Description' => sprintf($_LANG['sinusdocker_prefix_container_des'], 'billing_' . $_LANG['sinusdocker_id_product']),
        ), $_LANG['sinusdocker_Delay_before_reading_log'] => array(
            'Type' => 'text',
            'Size' => '1',
            'Default' => '1',
            'Description' => $_LANG['sinusdocker_Delay_before_reading_log_des'],
    ));
}

/**
 * Действия при создании нового экземляра услуги/продукта
 *

 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function sinusdocker_CreateAccount(array $params) {
    try {
        if (!DBhelper::Product_custom_fields_exists($params['pid'], 'port'))
            DBhelper::Product_custom_fields_text_add($params['pid'], 'port');

        if (!DBhelper::Product_custom_fields_exists($params['pid'], 'id container'))
            DBhelper::Product_custom_fields_text_add($params['pid'], 'id container');

        $serviceid = $params['serviceid'];
        $template = $params['configoption1'];
        $prefix = $params['configoption3'];
        $pause = $params['configoption4'];
        $url = $params['serverhttpprefix'] . '://' . $params['serverhostname'] . ':' . $params['serverport'];
        $repasswd = "/(?>33m)(.*)(?=\\[)/";
        $repasswdDEF = "/(?>\')(.*)(\'<?)/U";
        $serverusername = $params['serverusername'];
        $serverpassword = $params['serverpassword'];

        $shipyard = new shipyard();

        //  получаем токен
        $token = $shipyard->autn($serverusername, $serverpassword, $url);

        // создаем контейнер
        $idcontainer = $shipyard->containerscreate($serviceid, $template, $serverusername, $token, $url, $prefix);

        //запускаем контейнер
        $shipyard->containersrestart($idcontainer, $serverusername, $token, $url);
        sleep($pause);
        //получаем лог для извлечения пароля 
        $rawdata = $shipyard->containerslogs($idcontainer, $serverusername, $token, $url);

        //Получаем пароль из лога
        if (!preg_match($repasswd, $rawdata, $matches))
            preg_match_all($repasswdDEF, $rawdata, $matches);

        // passwd sinusbot:  $matches[1]
        // получаем информацию о контейнере
        $data = $shipyard->containersjson($idcontainer, $serverusername, $token, $url);

        // здесь магический костыль так как здесь есть название класса 8087/tcp
        // мы преобразуем обьект в масив
        $data = get_object_vars($data->NetworkSettings->Ports);
        $HostPort = $data['8087/tcp']['0']->HostPort;

        logModuleCall('TeamSpeak_3', __CLASS__ . '->' . __FUNCTION__, NULL, NULL, $rawdata, null);
        logModuleCall('TeamSpeak_3', __CLASS__ . '->' . __FUNCTION__, NULL, NULL, $$matches, null);

        $passwd = $matches[1];
        if (empty($passwd))
            throw new Exception('При парсинге лога не был найден пароль, попробуйте увеличить задержку в настройках модуля (услуги)');
        // обновляем информацию
        $command = "updateclientproduct";
        $values["serviceid"] = $params['serviceid'];
        $values["serviceusername"] = 'admin';
        $values["servicepassword"] = $matches[1];
        $values["domain"] = $params['configoption2'] . '://' . $params['serverhostname'] . ':' . $HostPort . '/';
        $values["customfields"] = base64_encode(serialize(array("port" => $HostPort, "id container" => "$idcontainer")));

        $results = localAPI($command, $values, 1);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
                'sinusdocker', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Приостановка продукта/услуги
 *
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function sinusdocker_SuspendAccount(array $params) {
    try {
        $url = $params['serverhttpprefix'] . '://' . $params['serverhostname'] . ':' . $params['serverport'];

        $shipyard = new shipyard();

        //  получаем токен
        $token = $shipyard->autn($params['serverusername'], $params['serverpassword'], $url);

        // Останавливаем контейнер
        $shipyard->containersstop($params['customfields']['id container'], $params['serverusername'], $token, $url);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
                'sinusdocker', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Un-suspend instance of a product/service.
 *
 * Called when an un-suspension is requested. This is invoked
 * automatically upon payment of an overdue invoice for a product, or
 * can be called manually by admin user.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function sinusdocker_UnsuspendAccount(array $params) {
    try {

        $url = $params['serverhttpprefix'] . '://' . $params['serverhostname'] . ':' . $params['serverport'];

        $shipyard = new shipyard();

        //  получаем токен
        $token = $shipyard->autn($params['serverusername'], $params['serverpassword'], $url);

        //запускаем контейнер
        $shipyard->containersrestart($params['customfields']['id container'], $params['serverusername'], $token, $url);

        // получаем информацию о контейнере
        $data = $shipyard->containersjson($params['customfields']['id container'], $params['serverusername'], $token, $url);

        // здесь магический костыль так как здесь есть название класса 8087/tcp
        // мы преобразуем обьект в масив
        $data = get_object_vars($data->NetworkSettings->Ports);
        $HostPort = $data['8087/tcp']['0']->HostPort;


        // обновляем информацию
        $command = "updateclientproduct";
        $values["serviceid"] = $params['serviceid'];
        $values["domain"] = $params['configoption2'] . '://' . $params['serverhostname'] . ':' . $HostPort . '/';
        $values["customfields"] = base64_encode(serialize(array("port" => $HostPort)));

        $results = localAPI($command, $values, 1);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
                'sinusdocker', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Terminate instance of a product/service.
 *
 * Called when a termination is requested. This can be invoked automatically for
 * overdue products if enabled, or requested manually by an admin user.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return string "success" or an error message
 */
function sinusdocker_TerminateAccount(array $params) {
    try {

        $url = $params['serverhttpprefix'] . '://' . $params['serverhostname'] . ':' . $params['serverport'];

        $shipyard = new shipyard();

        //  получаем токен
        $token = $shipyard->autn($params['serverusername'], $params['serverpassword'], $url);

        // удаляем
        $shipyard->containersdelete($params['customfields']['id container'], $params['serverusername'], $token, $url);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
                'sinusdocker', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Проверка соединения с сервером.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return array
 */
function sinusdocker_TestConnection(array $params) {
    try {
        $success = true;
        $errorMsg = '';

        $url = $params['serverhttpprefix'] . '://' . $params['serverhostname'] . ':' . $params['serverport'];
        $shipyard = new shipyard();

        $data = $shipyard->autn($params['serverusername'], $params['serverpassword'], $url);
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
                'sinusdocker', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString()
        );

        $success = false;
        $errorMsg = $e->getMessage();
    }

    return array(
        'success' => $success,
        'error' => $errorMsg,
    );
}

/**
 * Client area output logic handling.
 *
 * This function is used to define module specific client area output. It should
 * return an array consisting of a template file and optional additional
 * template variables to make available to that template.
 *
 * The template file you return can be one of two types:
 *
 * * tabOverviewModuleOutputTemplate - The output of the template provided here
 *   will be displayed as part of the default product/service client area
 *   product overview page.
 *
 * * tabOverviewReplacementTemplate - Alternatively using this option allows you
 *   to entirely take control of the product/service overview page within the
 *   client area.
 *
 * Whichever option you choose, extra template variables are defined in the same
 * way. This demonstrates the use of the full replacement.
 *
 * Please Note: Using tabOverviewReplacementTemplate means you should display
 * the standard information such as pricing and billing details in your custom
 * template or they will not be visible to the end user.
 *
 * @param array $params common module parameters
 *
 * @see http://docs.whmcs.com/Provisioning_Module_SDK_Parameters
 *
 * @return array
 */
function sinusdocker_ClientArea(array $params) {
    // Determine the requested action and set service call parameters based on
    // the action.
    $requestedAction = isset($_REQUEST['customAction']) ? $_REQUEST['customAction'] : '';

    $serverusername = $params['serverusername'];
    $serverpassword = $params['serverpassword'];
    $url = $params['serverhttpprefix'] . '://' . $params['serverhostname'] . ':' . $params['serverport'];

    $serviceAction = 'get_stats';
    $templateFile = 'templates/overview.tpl';

    try {

        $response = array();

        $shipyard = new shipyard();

        //  получаем токен
        $token = $shipyard->autn($serverusername, $serverpassword, $url);
        //получаем лог для извлечения пароля 
        $logs = $shipyard->containerslogs($params['customfields']['id container'], $serverusername, $token, $url);
        return array(
            'tabOverviewReplacementTemplate' => $templateFile,
            'templateVariables' => array(
                'logs' => $logs,
            ),
        );
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
                'sinusdocker', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString()
        );

        // In an error condition, display an error page.
        return array(
            'tabOverviewReplacementTemplate' => 'error.tpl',
            'templateVariables' => array(
                'usefulErrorHelper' => $e->getMessage(),
            ),
        );
    }
}

function loadLang($lang = null) {
    global $_LANG, $CONFIG;
    $Langpath = (dirname(__FILE__) . '/lang/');

    if (empty($lang)) {
        $Language = isset($_SESSION['Language']) ? $_SESSION['Language'] : $CONFIG['Language'];
    } else {
        $Language = $lang;
    }

    $LanguageFile = $Language . '.php';

    if (!file_exists($Langpath . $LanguageFile)) {
        $LanguageFile = 'russian.php';
    }

    include $Langpath . $LanguageFile;
}
