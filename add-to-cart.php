<?php
ini_set('display_errors', 1);

use Magento\Framework\App\Bootstrap;


class AddToCart
{

    private $request;
    private $filter;
    private $connection;
    private $checkoutSession;
    private $storeManager;
    private $customerSession;
    private $timezone;

    const ERROR = 'ERROR';
    const STATUS = 'status';
    const NOTAVAILABLE = 'NOTAVAILABLE';
    const SUCCESS = 'SUCCESS';

    /**
     * __construct data
     */
    function __construct()
    {
        require 'app/bootstrap.php';

//initiate magento
        $bootstrap = Bootstrap::create(BP, $_SERVER);
        $objectManager = $bootstrap->getObjectManager();
        $state = $objectManager->get('Magento\Framework\App\State');
        $state->setAreaCode('frontend');

//initiate request interface
        $this->request = $objectManager->get('\Magento\Framework\App\Request\Http');
        $this->filter = $objectManager->get('\Magento\Framework\Filter\LocalizedToNormalized');
        $resourceConnection = $objectManager->get('\Magento\Framework\App\ResourceConnection');
        $this->connection = $resourceConnection->getConnection();
        $this->checkoutSession = $objectManager->get('\Magento\Checkout\Model\Session');
        $this->storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
        $this->customerSession = $objectManager->get('Magento\Customer\Model\Session');
        $this->timezone = $objectManager->get('Magento\Framework\Stdlib\DateTime\TimezoneInterface');
        $this->pcrHelper = $objectManager->get('\Plumrocket\CartReservation\Helper\Data');
        $this->configHelper = $objectManager->get('\Plumrocket\CartReservation\Helper\Config');
    }

    /**
     * @param type $result
     * @return type array
     */
    function getSpecialPrice($result)
    {
        $itemPrice = 0;

        if (isset($result[0])) {
            if (isset($result[0]['rprice']) && $result[0]['rprice'] != null) {
                $itemPrice = $result[0]['rprice'];
            } elseif (isset($result[0]['special_price']) && $result[0]['special_price'] != '') {
                $itemPrice = $result[0]['special_price'];
            } else {
                $itemPrice = $result[0]['price'];
            }
        }

        return $itemPrice;
    }

    /**
     * @param type $quoteItemTable
     * @param type $websiteId
     * @param type $currentDate
     * @param type $groupId
     * @param type $storeId
     * @param type $productId
     * @param type $lastInsertQuoteId
     * @return type array
     */
    function getQuoteItemDetails($quoteItemTable, $websiteId, $currentDate, $groupId, $storeId, $productId, $lastInsertQuoteId, $parentExists, $parentProductId)
    { //echo "1"; exit;
        if ($parentExists) {
            $fields = ['sfqi.item_id as item_id', 'sfqi.parent_item_id as parent_item_id', 'sfqi.qty as iqty', 'cpf.final_price as special_price', 'cpf.price as price', 'cpp.rule_price as rprice'];
            $joinCPF = 'sfqi.product_id = cpf.entity_id';
            $joinCPP = "cpp.product_id = cpf.entity_id and cpp.website_id = $websiteId and cpp.rule_date = '$currentDate' and cpp.customer_group_id = $groupId";
            $sql = $this->connection->select()
                ->from(['sfqi' => $quoteItemTable], $fields)
                ->join(['cpf' => "catalog_product_index_price"], $joinCPF, [])
                ->joinLeft(['cpp' => "catalogrule_product_price"], $joinCPP, [])
                ->where("sfqi.product_id = '$productId' and sfqi.quote_id = '$lastInsertQuoteId' and sfqi.parent_item_id in (select item_id from quote_item where quote_id=$lastInsertQuoteId and product_id=$parentProductId)");
            $result = $this->connection->fetchAll($sql);
        } else {
            $fields = ['sfqi.item_id as item_id', 'sfqi.parent_item_id as parent_item_id', 'sfqi.qty as iqty', 'cpf.final_price as special_price', 'cpf.price as price', 'cpp.rule_price as rprice'];
            $joinCPF = "sfqi.product_id = cpf.entity_id and website_id=$websiteId and customer_group_id=$groupId";
            $joinCPP = "cpp.product_id = cpf.entity_id and cpp.website_id = $websiteId and cpp.rule_date = '$currentDate' and cpp.customer_group_id = $groupId";
            $sql = $this->connection->select()
                ->from(['sfqi' => $quoteItemTable], $fields)
                ->join(['cpf' => "catalog_product_index_price"], $joinCPF, [])
                ->joinLeft(['cpp' => "catalogrule_product_price"], $joinCPP, [])
                ->where("sfqi.product_id = '$productId' and sfqi.quote_id = '$lastInsertQuoteId' and sfqi.parent_item_id is null");
            $result = $this->connection->fetchAll($sql);
        }

        return $result;
    }

    /**
     * @param type $quoteItemTable
     * @param type $productId
     * @param type $lastInsertQuoteId
     * @return type array
     */
    function getQuoteItemInvDetails($quoteItemTable, $productId, $lastInsertQuoteId, $websiteId)
    {//echo "2"; exit;
		$sql="select sum(qi.qty) as qty from quote_item qi
		WHERE (qi.product_id = '$productId' and qi.quote_id = '$lastInsertQuoteId') ";
		$qty = $this->connection->fetchOne($sql);
		if (empty($qty)){ $qty=0; }
		
		$sql = "select (sum(csi.quantity) -(-IFNULL(reserved.rquantity ,0))-$qty) as qty, $qty as iqty from inventory_source_item csi
			join inventory_source_stock_link issl on issl.source_code = csi.source_code
			join inventory_stock_sales_channel issc on issc.stock_id = issl.stock_id
			join store_website sw on sw.code = issc.code and sw.website_id=$websiteId
			join catalog_product_entity cpe on cpe.sku = csi.sku
			LEFT JOIN (SELECT SUM(quantity) AS rquantity, inventory_reservation.sku FROM inventory_reservation GROUP BY sku) as reserved ON cpe.sku = reserved.sku
			where cpe.entity_id = '$productId'
            having iqty >= '1' limit 1";
        $result = $this->connection->fetchAll($sql);
		
        return $result;
    }

    /**
     * @param type $stockItemTable
     * @param type $websiteId
     * @param type $currentDate
     * @param type $groupId
     * @param type $storeId
     * @param type $productId
     * @param type $qty
     * @return type array
     */
    function getQtyItemDetails($stockItemTable, $reservationTable, $websiteId, $currentDate, $groupId, $storeId, $productId, $qty)
    {//echo "3"; exit;
		$sql = "select sum(csi.quantity), sum(csi.quantity) -(-IFNULL(reserved.rquantity ,0)) as qty, cpf.final_price as special_price, cpf.price as price, 		cpp.rule_price as rprice from inventory_source_item csi
			join inventory_source_stock_link issl on issl.source_code = csi.source_code
			join inventory_stock_sales_channel issc on issc.stock_id = issl.stock_id
			join store_website sw on sw.code = issc.code and sw.website_id=$websiteId
			join catalog_product_entity cpe on cpe.sku = csi.sku
            join catalog_product_index_price cpf on cpe.entity_id = cpf.entity_id and cpf.website_id=$websiteId and cpf.customer_group_id = $groupId
			LEFT JOIN (SELECT SUM(quantity) AS rquantity, inventory_reservation.sku FROM inventory_reservation GROUP BY sku) as reserved ON cpe.sku = reserved.sku
            left join catalogrule_product_price cpp on cpp.product_id = cpf.entity_id and cpp.website_id=$websiteId and cpp.rule_date = '$currentDate' and cpp.customer_group_id = $groupId
            where cpe.entity_id = '$productId'
            having qty >= '$qty' limit 1";
        $result = $this->connection->fetchAll($sql);
		
        return $result;
    }

    /**
     * @param type $lastInsertQuoteId
     * @return type array
     */
    function updateQuote($lastInsertQuoteId)
    {
        $sql = "update quote as sfq
inner join (select quote_id, count(*) as totalRows, sum(qty) as totalQty, sum(row_total) as rowTotal from quote_item
where quote_id='$lastInsertQuoteId' group by quote_id) as sfqi
on sfq.entity_id = sfqi.quote_id
set
sfq.updated_at = now(),
sfq.items_count = sfqi.totalRows,
sfq.items_qty = sfqi.totalQty,
sfq.grand_total = sfqi.rowTotal,
sfq.base_grand_total = sfqi.rowTotal,
sfq.subtotal = sfqi.rowTotal,
sfq.base_subtotal = sfqi.rowTotal,
sfq.subtotal_with_discount = sfqi.rowTotal,
sfq.base_subtotal_with_discount = sfqi.rowTotal";
        $result = $this->connection->query($sql);

        return $result;
    }

    /**
     * @return type array
     */
    function getCartData($lastInsertQuoteId)
    {
        $sql = "select subtotal, items_qty from quote where entity_id='$lastInsertQuoteId' limit 1";
        $cartData = $this->connection->fetchAll($sql);

        return $cartData;
    }

    /**
     * @param type $lastInsertId
     * @param type $parentProductId
     * @param type $jsonParams
     * @param type $superAttribute
     * @param type $productId
     * @param type $qty
     * @param type $lastItemInsertId
     */
    function insertOptionData($lastInsertId, $parentProductId, $jsonParams, $superAttribute, $productId, $qty, $lastItemInsertId, $optionTableName)
    {
        $data = [
            ['item_id' => $lastInsertId, 'product_id' => $parentProductId, 'code' => 'info_buyRequest', 'value' => $jsonParams],
            ['item_id' => $lastInsertId, 'product_id' => $parentProductId, 'code' => 'attributes', 'value' => $superAttribute],
            ['item_id' => $lastInsertId, 'product_id' => $productId, 'code' => 'product_qty_' . $productId, 'value' => $qty],
            ['item_id' => $lastInsertId, 'product_id' => $productId, 'code' => 'simple_product', 'value' => $productId],
            ['item_id' => $lastItemInsertId, 'product_id' => $productId, 'code' => 'info_buyRequest', 'value' => $jsonParams],
            ['item_id' => $lastItemInsertId, 'product_id' => $productId, 'code' => 'parent_product_id', 'value' => $parentProductId]
        ];

        $this->connection->insertMultiple($optionTableName, $data);

        return true;
    }

    /**
     * @param type $optionTableName
     * @param type $data
     * @return boolean
     */
    function updateOptionData($optionTableName, $parentItemId, $productId, $totalQty)
    {
        $data = ['value' => $totalQty];
        $this->connection->update(
            $optionTableName,
            $data,
            "item_id = '$parentItemId' and product_id = '$productId' and code = 'product_qty_$productId'"
        );

        return true;
    }

    /**
     * @param type $storeId
     * @param type $totalQty
     * @param type $currencyCode
     * @param type $itemTotal
     * @param type $customerId
     * @param type $email
     * @param type $firstName
     * @param type $middleName
     * @param type $lastName
     * @param type $isGuest
     * @return type int
     */
    function insertQuoteData($storeId, $totalQty, $currencyCode, $itemTotal, $customerId, $email, $firstName, $middleName, $lastName, $isGuest)
    {//echo "4"; exit;
        $query = "insert into quote (store_id, created_at, updated_at, is_active, items_count, items_qty, base_currency_code,
store_currency_code, quote_currency_code, grand_total, base_grand_total, subtotal, base_subtotal, subtotal_with_discount,
base_subtotal_with_discount, customer_id, customer_tax_class_id, customer_email, customer_firstname, customer_middlename,
customer_lastname, customer_note_notify, customer_is_guest, global_currency_code, base_to_global_rate, base_to_quote_rate, is_changed)
values('$storeId', now(), now(), 1, 1, '$totalQty', '$currencyCode', '$currencyCode', '$currencyCode', '$itemTotal', '$itemTotal', '$itemTotal', "
            . "'$itemTotal', '$itemTotal', '$itemTotal', '$customerId', 3, '$email', '$firstName', '$middleName', '$lastName', 1, '$isGuest', "
            . "'$currencyCode', 1, 1, 1)";
        $result = $this->connection->query($query);
        $lastInsertQuoteId = $this->connection->lastInsertId();

        return $lastInsertQuoteId;
    }

    /**
     * @param type $totalQty
     * @param type $itemPrice
     * @param type $priceTotal
     * @param type $lastInsertQuoteId
     * @param type $productId
     * @return type int
     */
    function updateQuoteItemData($itemId, $totalQty, $itemPrice, $priceTotal, $lastInsertQuoteId, $productId, $parentExists, $parentItemId)
    {//echo "5"; exit;
        $expireAt = $this->pcrHelper->getExpireAt(
            $this->configHelper->getCartTime()
        );

        if ($parentExists) {
            $sql = "update quote_item set qty='$totalQty' where item_id='$itemId'";
            $result = $this->connection->query($sql);

            $sql = "update quote_item set qty='$totalQty', price='$itemPrice', base_price='$itemPrice', row_total='$priceTotal', base_row_total='$priceTotal', price_incl_tax='$itemPrice', "
                . "base_price_incl_tax='$itemPrice', row_total_incl_tax='$priceTotal', base_row_total_incl_tax='$priceTotal', timer_expire_at=100 where item_id='$parentItemId' and quote_id='$lastInsertQuoteId'";
            $result = $this->connection->query($sql);
        } else {
            $sql = "update quote_item set qty='$totalQty', price='$itemPrice', base_price='$itemPrice', row_total='$priceTotal', base_row_total='$priceTotal', price_incl_tax='$itemPrice', "
                . "base_price_incl_tax='$itemPrice', row_total_incl_tax='$priceTotal', base_row_total_incl_tax='$priceTotal' , timer_expire_at=100 where item_id='$itemId' and quote_id='$lastInsertQuoteId' and product_id='$productId'";
            $result = $this->connection->query($sql);
        }

        $lastItemInsertId = $this->connection->lastInsertId();

        return $lastItemInsertId;
    }

    function getProductName($productId, $storeId)
    {
        $fields = ['cvar.value as name'];
        $fromTable = 'catalog_product_entity_varchar';
        $sql = $this->connection->select()
            ->from(['cvar' => $fromTable], $fields)
            ->where("cvar.entity_id = '$productId' and cvar.attribute_id = (SELECT attribute_id FROM eav_attribute WHERE entity_type_id=4 AND attribute_code='name') and cvar.store_id in (0, '$storeId')")
            ->order('cvar.store_id desc')
            ->limit(1);
        $result = $this->connection->fetchOne($sql);
    }

    /**
     * @param type $lastInsertQuoteId
     * @param type $storeId
     * @param type $qty
     * @param type $itemPrice
     * @param type $parentProductId
     * @return type
     */
    function insertConfigItemData($lastInsertQuoteId, $storeId, $qty, $itemPrice, $parentProductId, $name, $groupId, $websiteId)
    { //echo "6"; exit;
        $expireAt = $this->pcrHelper->getExpireAt(
            $this->configHelper->getCartTime()
        );
        $query = "insert into quote_item (quote_id, created_at, updated_at, product_id, store_id, is_virtual, sku, name, is_qty_decimal,
qty, price, base_price, row_total, base_row_total, product_type, price_incl_tax, base_price_incl_tax, row_total_incl_tax,
base_row_total_incl_tax,timer_expire_at)
(select '$lastInsertQuoteId', now(), now(), cpe.entity_id, '$storeId', 0, cpe.sku, '$name', 1, '$qty', '$itemPrice', "
            . "'$itemPrice', $itemPrice*$qty, $itemPrice*$qty, cpe.type_id, '$itemPrice', '$itemPrice', $itemPrice*$qty, "
            . "$itemPrice*$qty from catalog_product_index_price flat join catalog_product_entity cpe on cpe.entity_id = flat.entity_id and flat.customer_group_id=$groupId and website_id=$websiteId "
            . "where cpe.entity_id='$parentProductId',$expireAt)";
        $result = $this->connection->query($query);
        $lastInsertId = $this->connection->lastInsertId();

        return $lastInsertId;
    }

    /**
     * @param type $lastInsertQuoteId
     * @param type $storeId
     * @param type $lastInsertId
     * @param type $qty
     * @param type $productId
     * @return type
     */
    function insertSimpleConfigItemData($lastInsertQuoteId, $storeId, $lastInsertId, $qty, $productId, $name, $groupId, $websiteId)
    {//echo "7"; exit;
        $query = "insert into quote_item (quote_id, created_at, updated_at, product_id, store_id, parent_item_id, is_virtual, sku, name, is_qty_decimal,
qty, product_type)
(select '$lastInsertQuoteId', now(), now(), cpe.entity_id, '$storeId', $lastInsertId, 0, cpe.sku, '$name', 1, '$qty', cpe.type_id "
            . "from catalog_product_index_price flat join catalog_product_entity cpe on cpe.entity_id = flat.entity_id and flat.customer_group_id=$groupId and website_id=$websiteId "
            . "where cpe.entity_id='$productId')";
        $result = $this->connection->query($query);
        $lastItemInsertId = $this->connection->lastInsertId();

        return $lastItemInsertId;
    }

    /**
     * @param type $lastInsertQuoteId
     * @param type $storeId
     * @param type $qty
     * @param type $itemPrice
     * @param type $productId
     */
    function insertSimpleItemData($lastInsertQuoteId, $storeId, $qty, $itemPrice, $productId, $name, $groupId, $websiteId)
    {//echo "8"; exit;
        $expireAt = $this->pcrHelper->getExpireAt(
            $this->configHelper->getCartTime()
        );
        $query = "insert into quote_item (quote_id, created_at, updated_at, product_id, store_id, is_virtual, sku, name, is_qty_decimal,
qty, price, base_price, row_total, base_row_total, product_type, price_incl_tax, base_price_incl_tax, row_total_incl_tax,
base_row_total_incl_tax,timer_expire_at,original_cart_expire_at)
(select '$lastInsertQuoteId', now(), now(), cpe.entity_id, '$storeId', 0, cpe.sku, '$name', 1, '$qty', '$itemPrice', "
            . "'$itemPrice', $itemPrice*$qty, $itemPrice*$qty, cpe.type_id, '$itemPrice', '$itemPrice', $itemPrice*$qty, "
            . "$itemPrice*$qty,$expireAt,$expireAt from catalog_product_index_price flat join catalog_product_entity cpe on cpe.entity_id = flat.entity_id and flat.customer_group_id=$groupId and website_id=$websiteId "
            . "where cpe.entity_id='$productId')";
        $result = $this->connection->query($query);
        $lastItemInsertId = $this->connection->lastInsertId();

        return $lastItemInsertId;
    }

    /**
     * @param type $lastInsertQuoteId
     * @return type array
     */
    function cartItemsData($lastInsertQuoteId)
    {
        $sql = "select sku, name, qty, price, row_total, product_id from quote_item where parent_item_id is null and quote_id='$lastInsertQuoteId'";
        $cartItemData = $this->connection->fetchAll($sql);

        $items = [];
        if (count($cartItemData)) {
            foreach ($cartItemData as $item) {
                $items[] = ['name' => $item['name'], 'qty' => $item['qty'], 'sku' => $item['sku'], 'price' => $item['price'], 'row_total' => $item['row_total'], 'product_id' => $item['product_id']];
            }
        }

        return $items;
    }

    /**
     *
     */
    function addToCart()
    {
        $params = $this->request->getParams();
        $response[self::STATUS] = self::ERROR;

        if (array_key_exists("qty",$params)){            //echo "Key exists!";
        }else{
            $params['qty']=1;
        }

        if (isset($params['qty']) && !is_numeric($params['qty'])) {
            echo json_encode($response);
            die;
        }

        if (!isset($params['product'])) {
            echo json_encode($response);
            die;
        }

//initialize data
        $productId = (int)$params['product'];
        $parentExists = false;
        $parentProductId = '';
        $configurableOptions = [];
        $itemPrice = 0;
        $quoteQty = 0;
        $quoteItemExists = false;
        $groupId = $this->customerSession->getCustomer()->getGroupId();
//current date
        $currentDate = $this->timezone->date()->format('Y-m-d');

//simple product check , uncomment this for config product
       // $productId = $params['selected_configurable_option'];
      //  unset($params['selected_configurable_option']);

//initialize configurable product data
        if (isset($params['selected_configurable_option']) && !empty($params['selected_configurable_option'])) {
            $parentProductId = (int)$productId;
            $productId = (int)$params['selected_configurable_option'];
            $parentExists = true;

            $superAttribute = json_encode($params['super_attribute']);
            $jsonParams = json_encode($params);
        }

//fetch qty
        $qty = 1;
        if (isset($params['qty'])) {
            $qty = (int)$params['qty'];
        }

//clean data
        $qty = (int)$this->filter->filter($qty);
        $productId = (int)$this->filter->filter($productId);
        $currencyCode = (string)$this->filter->filter($params['currencyCode']);
        $storeId = (int)$this->filter->filter($params['storeId']);
        $websiteId = (string)$this->filter->filter($params['websiteId']);
        $this->storeManager->setCurrentStore($storeId);

        if (!empty($productId)) {
            try {
//get customer quote
                $quoteId = $this->checkoutSession->getQuoteId();
                $lastInsertQuoteId = $quoteId;

                $quoteTable = 'quote';
                $quoteItemTable = 'quote_item';
                $stockItemTable = 'inventory_source_item';
                $optionTableName = 'quote_item_option';
				$reservationTable = 'inventory_reservation';
                $itemId = '';
                $parentItemId = '';

//CHECK IF QUOTE AVAILABLE
                if (isset($lastInsertQuoteId) && $lastInsertQuoteId != '') {
                    $result = $this->getQuoteItemInvDetails($quoteItemTable, $productId, $lastInsertQuoteId, $websiteId);

//IF QUOTE AND ITEM DETAILS NOT AVAILABLE, GET FROM FLAT
                    if (count($result) <= 0) {
                        $result = $this->getQtyItemDetails($stockItemTable, $reservationTable, $websiteId, $currentDate, $groupId, $storeId, $productId, $qty);

                        if (count($result) <= 0) {
                            $response['status'] = self::NOTAVAILABLE;
                            echo json_encode($response);
                            die;
                        } else {
                            $itemPrice = $this->getSpecialPrice($result);
                        }
                    } elseif (isset($result) && isset($result[0]) && isset($result[0]['qty']) && $result[0]['qty'] <= 0) {
                        $response['status'] = self::NOTAVAILABLE;
                        echo json_encode($response);
                        die;
                    } elseif (isset($result) && isset($result[0]) && isset($result[0]['iqty']) && $result[0]['iqty'] > 0) {
                        $result = $this->getQuoteItemDetails($quoteItemTable, $websiteId, $currentDate, $groupId, $storeId, $productId, $lastInsertQuoteId, $parentExists, $parentProductId);
                        $itemPrice = $this->getSpecialPrice($result);
                        $quoteQty = $result[0]['iqty'] ?? 0;

                        if (count($result) && !$parentExists && $result[0]['parent_item_id'] == '') {
                            $quoteItemExists = true;
                        } elseif (count($result) && $parentExists && $result[0]['parent_item_id'] != '') {
                            $quoteItemExists = true;
                        }

                        $itemId = $result[0]['item_id'] ?? '';
                        $parentItemId = $result[0]['parent_item_id'] ?? '';
                    }
                } else {
//IF QUOTE NOT AVAILABLE, GET ITEM DETAILS FROM FLAT
                    $result = $this->getQtyItemDetails($stockItemTable, $websiteId, $currentDate, $groupId, $storeId, $productId, $qty);

                    if (count($result) <= 0) {
                        $response['status'] = self::NOTAVAILABLE;
                        echo json_encode($response);
                        die;
                    } else {
                        if (isset($result[0])) {
                            $itemPrice = $this->getSpecialPrice($result);
                        }
                    }
                }

                if (empty($quoteId) || $quoteId == '') {
                    $customerId = 0;
                    $email = $firstName = $middleName = $lastName = '';
                    $isGuest = 1;
//USER , CHECK LOGGED IN AND SET DATA IN QUOTE
                    if ($this->customerSession->isLoggedIn()) {
                        $customer = $this->checkoutSession->getCustomer();
                        $customerId = $customer->getId();
                        $email = $customer->getEmail();
                        $firstName = $customer->getFirstname();
                        $middleName = $customer->getMiddlename();
                        $lastName = $customer->getLastname();
                        $isGuest = 0;
                    }

//CREATE QUOTE IF NOT AVAILABLE
                    $totalQty = $qty;
                    $itemTotal = $itemPrice * $totalQty;

                    $lastInsertQuoteId = $this->insertQuoteData($storeId, $totalQty, $currencyCode, $itemTotal, $customerId, $email, $firstName, $middleName, $lastName, $isGuest);
                }

//QUOTE ITEM IF AVAILABLE, UPDATE AND IF NOT , CREATE
                $lastItemInsertId = '';
                if ($quoteItemExists) {
                    $totalQty = $qty + $quoteQty;
                    $priceTotal = $itemPrice * $totalQty;

//update data if quote item exists
                    if ($parentExists) {
//quote item update
                        $lastItemInsertId = $this->updateQuoteItemData($itemId, $totalQty, $itemPrice, $priceTotal, $lastInsertQuoteId, $productId, $parentExists, $parentItemId);

//update quote option data
//$this->updateOptionData($optionTableName, $parentItemId, $productId, $totalQty);
                    } else {
//quote item update
                        $lastItemInsertId = $this->updateQuoteItemData($itemId, $totalQty, $itemPrice, $priceTotal, $lastInsertQuoteId, $productId, $parentExists, $parentItemId);
                    }
                } else {
//insert for configurable product
                    if ($parentExists && $parentProductId != null) {
//config data
                        $name = $this->getProductName($parentProductId, $storeId);
                        $lastInsertId = $this->insertConfigItemData($lastInsertQuoteId, $storeId, $qty, $itemPrice, $parentProductId, $name, $groupId, $websiteId);

//simple product
                        $name = $this->getProductName($productId, $storeId);
                        $lastItemInsertId = $this->insertSimpleConfigItemData($lastInsertQuoteId, $storeId, $lastInsertId, $qty, $productId, $name, $groupId, $websiteId);

//create option data
                        $this->insertOptionData($lastInsertId, $parentProductId, $jsonParams, $superAttribute, $productId, $qty, $lastItemInsertId, $optionTableName);
                    } else {
//simple product
                        $name = $this->getProductName($productId, $storeId);
                        $lastItemInsertId = $this->insertSimpleItemData($lastInsertQuoteId, $storeId, $qty, $itemPrice, $productId, $name, $groupId, $websiteId);
                    }
                }

                $lastItemInsertId = $this->connection->lastInsertId();

//QUOTE UPDATE IF AVAILABLE
                if (isset($lastItemInsertId) && $lastItemInsertId != '') {
                    $result = $this->updateQuote($lastInsertQuoteId);

                    $response[self::STATUS] = self::SUCCESS;

//CREATE CALLBACK RESULTS DATA FROM CART UPDATE
                    $cartData = $this->getCartData($lastInsertQuoteId);

                    if (isset($cartData) && isset($cartData[0])) {
                        $cart = $cartData[0];
                        $itemCount = isset($cart['items_qty']) ? (int)$cart['items_qty'] : 0;
                        $cartTotal = isset($cart['subtotal']) ? sprintf('%0.2f', $cart['subtotal']) : 0;

                        $response['cartcount'] = $itemCount;
                        $response['cartsubtotal'] = $cartTotal;

//get basic info
                        $items = $this->cartItemsData($lastInsertQuoteId);
                        $response['items'] = $items;

//$period = 3600;	$path = '/'; $domain = null; $secure = null; $httponly = 0;
//set cookies if you need some data in cookie
                    }
                }

                if (isset($lastInsertQuoteId) && $lastInsertQuoteId != '')
                    $this->checkoutSession->setQuoteId($lastInsertQuoteId);
                $this->connection->closeConnection();
            } catch (Exception $e) {
                $response[self::STATUS] = 'CANNOT' . $e->getMessage();
            }
        } else {
            $response[self::STATUS] = self::ERROR;
        }

        echo json_encode($response);
        exit;
    }
}

$addCartObject = new AddToCart();
$addCartObject->addToCart();

//**** if cart item dissappears, check if your table has column 'timer_expire_at'. if yes, add this in query for quote_item (for community version)