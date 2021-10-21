<?php

use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Controller\PhoneAuth;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;
use Bitrix\Sale;
use Bitrix\Sale\Delivery;
use Bitrix\Sale\DiscountCouponsManager;
use Bitrix\Sale\Location\GeoIp;
use Bitrix\Sale\Location\LocationTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem;
use Bitrix\Sale\PersonType;
use Bitrix\Sale\Result;
use Bitrix\Sale\Services\Company;
use Bitrix\Sale\Shipment;
use Bitrix\Main\Security\Random;

//use Bitrix\Sale\Registry;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
	die();

/**
 * @var $APPLICATION CMain
 * @var $USER CUser
 */

Loc::loadMessages(__FILE__);

if (!Loader::includeModule("sale"))
{
	ShowError(Loc::getMessage("SOA_MODULE_NOT_INSTALL"));

	return;
}

class SaleOrderAjax extends \CBitrixComponent
{
	const AUTH_BLOCK = 'AUTH';
	const REGION_BLOCK = 'REGION';
	const PAY_SYSTEM_BLOCK = 'PAY_SYSTEM';
	const DELIVERY_BLOCK = 'DELIVERY';
	const PROPERTY_BLOCK = 'PROPERTY';

	/** @var Order $order */
	protected $order;
	/** @var Sale\Basket\Storage $basketStorage */
	protected $basketStorage;
	/** @var Sale\Basket */
	private $calculateBasket;

	protected $action;
	protected $arUserResult;
	protected $isOrderConfirmed;
	protected $arCustomSelectFields = [];
	protected $arElementId = [];
	protected $arSku2Parent = [];
	/** @var Delivery\Services\Base[] $arDeliveryServiceAll */
	protected $arDeliveryServiceAll = [];
	protected $arPaySystemServiceAll = [];
	protected $arActivePaySystems = [];
	protected $arIblockProps = [];
	/** @var  PaySystem\Service $prePaymentService */
	protected $prePaymentService;
	protected $useCatalog;
	/** @var Main\Context $context */
	protected $context;
	protected $checkSession = true;
	protected $isRequestViaAjax;

	[...]
    
    protected function createShipment() {
        $shipmentCollection = $this->order->getShipmentCollection();

        if (intval($this->request['delivery_id']) > 0) {
            $shipment = $shipmentCollection->createItem(
                Bitrix\Sale\Delivery\Services\Manager::getObjectById(
                    intval($this->request['delivery_id'])
                )
            );
        } else {
            $shipment = $shipmentCollection->createItem();
        }
        
        $shipmentItemCollection = $shipment->getShipmentItemCollection();
        $shipment->setField('CURRENCY', $this->order->getCurrency());

        foreach ($this->order->getBasket()->getOrderableItems() as $item) {
            $shipmentItem = $shipmentItemCollection->createItem($item);
            $shipmentItem->setQuantity($item->getQuantity());
        }
    }
    
    protected function createPaymentSystem() {
        if (intval($this->request['payment_id']) > 0) {
            $paymentCollection = $this->order->getPaymentCollection();
            $payment = $paymentCollection->createItem(
                Bitrix\Sale\PaySystem\Manager::getObjectById(
                    intval($this->request['payment_id'])
                )
            );
            $payment->setField("SUM", $this->order->getPrice());
            $payment->setField("CURRENCY", $this->order->getCurrency());
        }
    }

	protected function createVirtualOrder()
	{
		global $USER;
 
		try {
			$siteId = \Bitrix\Main\Context::getCurrent()->getSite();
			$basketItems = \Bitrix\Sale\Basket::loadItemsForFUser(
				\CSaleBasket::GetBasketUserID(), 
				$siteId
			)->getOrderableItems();
 
			if (count($basketItems) == 0) {
				LocalRedirect($this->arParams['PATH_TO_BASKET']);
			}
 
			$this->order = \Bitrix\Sale\Order::create($siteId, $USER->GetID());
			$this->order->setPersonTypeId($this->arParams['PERSON_TYPE_ID']);
			$this->order->setBasket($basketItems);

			$this->setOrderProps();
            $this->createShipment();
            $this->createPaymentSystem();
            
		} catch (\Exception $e) {
			$this->errors[] = $e->getMessage();
		}
	}

	protected function setOrderProps()
	{
		global $USER;
        
		$arUser = $USER->GetByID(intval($USER->GetID()))
			->Fetch();
 
		if (is_array($arUser)) {
			$fio = $arUser['LAST_NAME'] . ' ' . $arUser['NAME'] . ' ' . $arUser['SECOND_NAME'];
			$fio = trim($fio);
			$arUser['FIO'] = $fio;
		}
 
		foreach ($this->order->getPropertyCollection() as $prop) {
			$value = '';
 
			switch ($prop->getField('CODE')) {
				case 'FIO':
					$value = trim($this->request['fio']);
 
					if (empty($value)) {
						$value = $arUser['FIO'];
					}
					break;
                    
				case 'EMAIL':
					$value = trim($this->request['email']);
					break;
                    
				case 'PHONE':
					$value = trim($this->request['phone']);
					break;
                    
				case 'COMMENT':
					$value = trim($this->request['comment']);
					break;
 
				default:
			}
 
			if (empty($value)) {
				foreach ($this->request as $key => $val) {
					if (strtolower($key) == strtolower($prop->getField('CODE'))) {
						$value = $val;
					}
				}
			}
 
			if (empty($value)) {
				$value = $prop->getProperty()['DEFAULT_VALUE'];
			}
 
			if (!empty($value)) {
				$prop->setValue($value);
			}
		}
	}
    
    protected function checkUser() {
        global $USER;
        
        $login = preg_replace("/[^,.0-9]/", '', $this->request['phone']);
        
        $arFilter['LOGIN'] = $login;
        $dbUsers = CUser::GetList(($by="id"), ($order="desc"), $arFilter);
        while ($arUser = $dbUsers->Fetch())
        {
            $userId = $arUser["ID"];
            $userLogin = $arUser["LOGIN"];
        }
        
        if(empty($userLogin)) {
            $this->regUser();
        }
        else {
            $arAuthResult = $USER->Authorize($userId);
        }
    }
    
    protected function regUser() {
        $arr_username = explode(" ", $this->request['fio']);
        if(count($arr_username) == 1) {
            $arr_username[1] = $arr_username[0];
            $arr_username[0] = "";
        }
        
        $arr_group_ids = array(6);
        $company_name = "";

        $default_group = "individuals_users";
        switch ($default_group) {
            case "individuals_users":
                $arr_group_ids = array(6, 9);
                break;
            case "legal_users":
                $arr_group_ids = array(6, 10);
                $company_name = $_POST["company_name"];
                break;
        }
        
        $login = preg_replace("/[^,.0-9]/", '', $this->request['phone']);
        $password = Random::getString(10);
        
        $arFields = Array(
          "NAME"              => $arr_username[1],
          "LAST_NAME"         => $arr_username[0],
          "SECOND_NAME"       => $arr_username[2],
          "EMAIL"             => $this->request['email'],
          "LOGIN"             => $login,
          "PHONE_NUMBER"      => $this->request['phone'],
          "PERSONAL_PHONE"    => $this->request['phone'],
          "LID"               => SITE_ID,
          "ACTIVE"            => "Y",
          "UF_COMPANY_NAME"   => $company_name,
          "GROUP_ID"          => $arr_group_ids, //Зарегистрированные пользователи + физ или юр лица
          "PASSWORD"          => $password,
          "CONFIRM_PASSWORD"  => $password,
        );

        $USER_ID = CUser::Add($arFields);

        if (intval($USER_ID) > 0){
            $arAuthResult = CUser::Login($login, $password, "Y");
            $arEventFields = array(
                "USER_EMAIL" => $this->request['email'],
                "LOGIN" => $login,
                "PASSWORD" => $password,
            );
            
            CEvent::Send("REG_USER_BASKET", SITE_ID, $arEventFields);
            
        }else{
            $result['status'] = 'error';
            $result['message'] = html_entity_decode(CUser::LAST_ERROR);
            
            exit(json_encode($result));
        }
    }

	public function executeComponent()
	{
        global $USER;
        global $APPLICATION;
        
		if($this->request->isPost() && $this->request["add_order"]) {
            
			$APPLICATION->RestartBuffer();
            
            $user_id = $USER->GetID();
            if(empty($user_id) || $user_id == 0) {
                $this->checkUser();
            }

			$this->createVirtualOrder();
			$this->order->save();
            $res = $this->order->save();
            if ($res->isSuccess())
            {
                $arResult["ORDER_ID"] = $res->getId();
                $arResult["ACCOUNT_NUMBER"] = $this->order->getField('ACCOUNT_NUMBER');
                $result['status'] = "success";
                $result['order_id'] = $arResult["ORDER_ID"];
                
            }
            else {
                $result['status'] = "error";
            }
            
            exit(json_encode($result));
		}
		else {
			$this->setFrameMode(false);
			$this->context = Main\Application::getInstance()->getContext();
			$this->checkSession = $this->arParams["DELIVERY_NO_SESSION"] == "N" || check_bitrix_sessid();
			$this->isRequestViaAjax = $this->request->isPost() && $this->request->get('via_ajax') == 'Y';
			$isAjaxRequest = $this->request["is_ajax_post"] == "Y";

			if ($isAjaxRequest)
				$APPLICATION->RestartBuffer();

			$this->action = $this->prepareAction();
			Sale\Compatible\DiscountCompatibility::stopUsageCompatible();
			$this->doAction($this->action);
			Sale\Compatible\DiscountCompatibility::revertUsageCompatible();

			if (!$isAjaxRequest)
			{
				CJSCore::Init(['fx', 'popup', 'window', 'ajax', 'date']);
			}

			$this->includeComponentTemplate();

			if ($isAjaxRequest)
			{
				$APPLICATION->FinalActions();
				die();
			}
		}
	}
}