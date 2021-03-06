<?php

use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\AbstractDeliveryOptionsAdapter as DeliveryOptions;
use MyParcelNL\Sdk\src\Factory\ConsignmentFactory;
use MyParcelNL\Sdk\src\Helper\MyParcelCollection;
use MyParcelNL\Sdk\src\Model\Consignment\AbstractConsignment;
use MyParcelNL\Sdk\src\Model\MyParcelCustomsItem;
use WPO\WC\PostNL\Compatibility\Order as WCX_Order;
use WPO\WC\PostNL\Compatibility\Product as WCX_Product;

if (! defined("ABSPATH")) {
    exit;
} // Exit if accessed directly

if (class_exists("WCPN_Export_Consignments")) {
    return;
}

class WCPN_Export_Consignments
{
    /**
     * @var AbstractConsignment
     */
    private $consignment;

    /**
     * @var DeliveryOptions
     */
    private $deliveryOptions;

    /**
     * @var mixed
     */
    private $recipient;

    /**
     * @var WC_Order
     */
    private $order;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string|null
     */
    private $carrier;

    /**
     * @var PostNLCollection
     */
    public $postNLCollection;
    /**
     * @var \OrderSettings
     */
    private $orderSettings;

    /**
     * WCPN_Export_Consignments constructor.
     *
     * @param WC_Order $order
     *
     * @throws ErrorException
     * @throws Exception
     */
    public function __construct(WC_Order $order)
    {
        $this->getApiKey();

        $this->order           = $order;
        $this->deliveryOptions = WCPOST_Admin::getDeliveryOptionsFromOrder($order);
        $this->orderSettings   = new OrderSettings($this->deliveryOptions, $order);

        $this->carrier         = $this->deliveryOptions->getCarrier() ?? WCPN_Data::DEFAULT_CARRIER;

        $this->postNLCollection = (new MyParcelCollection())->setUserAgents(
            [
                'Wordpress'              => get_bloginfo('version'),
                'WooCommerce'            => WOOCOMMERCE_VERSION,
                'PostNL-WooCommerce' => WC_POSTNL_VERSION,
            ]
        );

        $this->createConsignment();
        $this->setConsignmentData();
    }

    /**
     * Create a new consignment
     *
     * @return void
     * @throws Exception
     */
    public function createConsignment(): void
    {
        $this->consignment = ConsignmentFactory::createByCarrierName($this->carrier);
    }

    /**
     * Set all the needed data for the consignment.
     *
     * @throws Exception
     */
    private function setConsignmentData(): void
    {
        $this->setBaseData();
        $this->setRecipient();
        $this->setShipmentOptions();
        $this->setPickupLocation();
        $this->setCustomsDeclaration();
        $this->setPhysicalProperties();
    }

    /**
     * @param string $name
     *
     * @return mixed
     */
    private function getSetting(string $name)
    {
        return WCPOST()->setting_collection->getByName($name);
    }

    /**
     * @return int
     */
    private function getPackageType(): int
    {
        return WCPN_Data::getPackageTypeId($this->orderSettings->getPackageType());
    }

    /**
     * @return int
     */
    private function getDeliveryType(): int
    {
        $deliveryTypeId = WCPN_Data::getDeliveryTypeId($this->deliveryOptions->getDeliveryType());

        return $deliveryTypeId ?? AbstractConsignment::DELIVERY_TYPE_STANDARD;
    }

    /**
     * Get date in YYYY-MM-DD HH:MM:SS format
     *
     * @return string
     */
    public function getDeliveryDate(): string
    {
        $date             = strtotime($this->deliveryOptions->getDate());
        $deliveryDateTime = date('Y-m-d H:i:s', $date);
        $deliveryDate     = date("Y-m-d", $date);
        $dateOfToday      = date("Y-m-d", strtotime('now'));
        $dateOfTomorrow   = date('Y-m-d H:i:s', strtotime('now +1 day'));

        if ($deliveryDate <= $dateOfToday) {
            return $dateOfTomorrow;
        }

        return $deliveryDateTime;
    }

    /**
     * @return void
     * @throws \MyParcelNL\Sdk\src\Exception\MissingFieldException
     * @throws \ErrorException
     */
    public function setCustomItems(): void
    {
        foreach ($this->order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $country = $this->getCountryOfOrigin($product);
            if (! empty($product)) {
                // Description
                $description = $item["name"];

                // GitHub issue https://github.com/postnlnl/woocommerce/issues/190
                if (strlen($description) >= WCPN_Export::ITEM_DESCRIPTION_MAX_LENGTH) {
                    $description = substr($item["name"], 0, 47) . "...";
                }
                // Amount
                $amount = (int) (isset($item["qty"]) ? $item["qty"] : 1);

                // Weight (total item weight in grams)
                $weight      = (int) round(WCPN_Export::getItemWeightKg($item, $this->order) * 1000);
                $totalWeight = $this->getTotalWeight($weight);

                $postNLItem = (new MyParcelCustomsItem())
                    ->setDescription($description)
                    ->setAmount($amount)
                    ->setWeight($totalWeight)
                    ->setItemValue((int) round(($item["line_total"] + $item["line_tax"]) * 100))
                    ->setCountry($country)
                    ->setClassification($this->getHsCode($product));

                $this->consignment->addItem($postNLItem);
            }
        }
    }

    /**
     * @param int $weight
     *
     * @return int
     */
    private function getTotalWeight(int $weight): int
    {
        $parcelWeight = (int) $this->getSetting(WCPOST_Settings::SETTING_EMPTY_PARCEL_WEIGHT);

        return $parcelWeight + $weight;
    }

    /**
     * @param WC_Product $product
     *
     * @return int
     * @throws \ErrorException
     */
    public function getHsCode(WC_Product $product): int
    {
        $defaultHsCode   = $this->getSetting(WCPOST_Settings::SETTING_HS_CODE);
        $productHsCode   = WCX_Product::get_meta($product, WCPOST_Admin::META_HS_CODE, true);
        $variationHsCode = WCX_Product::get_meta($product, WCPOST_Admin::META_HS_CODE_VARIATION, true);

        $hsCode = $productHsCode ? $productHsCode : $defaultHsCode;

        if ($variationHsCode) {
            $hsCode = $variationHsCode;
        }

        if (! $hsCode) {
            throw new ErrorException(__("No HS code found in PostNL settings", "woocommerce-postnl"));
        }

        return (int) $hsCode;
    }

    /**
     * @param WC_Product $product
     *
     * @return string
     */
    public function getCountryOfOrigin(WC_Product $product): string
    {
        $defaultCountryOfOrigin = $this->getSetting(WCPOST_Settings::SETTING_COUNTRY_OF_ORIGIN);
        $productCountryOfOrigin = WCX_Product::get_meta($product, WCPOST_Admin::META_COUNTRY_OF_ORIGIN, true);

        $countryOfOrigin = $this->getPriorityOrigin($defaultCountryOfOrigin, $productCountryOfOrigin);

        return (string) $countryOfOrigin;
    }

    /**
     * @param string|null $defaultCountryOfOrigin
     * @param string|null  $productCountryOfOrigin
     *
     * @return string
     */
    public function getPriorityOrigin(?string $defaultCountryOfOrigin, ?string $productCountryOfOrigin): string
    {
        if ($productCountryOfOrigin) {
            return $productCountryOfOrigin;
        }

        if ($defaultCountryOfOrigin) {
            return $defaultCountryOfOrigin;
        }

        return WC()->countries->get_base_country() ?? AbstractConsignment::CC_NL;
    }

    /**
     * @return AbstractConsignment
     */
    public function getConsignment(): AbstractConsignment
    {
        return $this->consignment;
    }

    /**
     * @return int
     */
    private function getContents(): int
    {
        return (int) ($this->getSetting("package_contents") ?? AbstractConsignment::PACKAGE_CONTENTS_COMMERCIAL_GOODS);
    }

    /**
     * Gets the recipient and puts its data in the consignment.
     *
     * @throws Exception
     */
    private function setRecipient(): void
    {
        $this->recipient = WCPN_Export::getRecipientFromOrder($this->order);

        $this->consignment
            ->setCountry($this->recipient['cc'])
            ->setPerson($this->recipient['person'])
            ->setCompany($this->recipient['company'])
            ->setStreet($this->recipient['street'])
            ->setNumber($this->recipient['number'] ?? null)
            ->setNumberSuffix($this->recipient['number_suffix'] ?? null)
            ->setStreetAdditionalInfo($this->recipient['street_additional_info'] ?? null)
            ->setPostalCode($this->recipient['postal_code'])
            ->setCity($this->recipient['city'])
            ->setEmail($this->recipient['email'])
            ->setPhone($this->recipient['phone']);
    }

    /**
     * @throws ErrorException
     */
    private function getApiKey(): void
    {
        $this->apiKey = $this->getSetting(WCPOST_Settings::SETTING_API_KEY);

        if (! $this->apiKey) {
            throw new ErrorException(__("No API key found in PostNL settings", "woocommerce-postnl"));
        }
    }

    /**
     * Get the label description from OrderSettings and replace any variables in it.
     *
     * @return string
     */
    private function getFormattedLabelDescription(): string
    {
        $productIds   = [];
        $productNames = [];
        $productSkus  = [];

        foreach ($this->order->get_items() as $item) {
            if (! method_exists($item, 'get_product')) {
                continue;
            }

            /** @var WC_Product $product */
            $product = $item->get_product();
            $sku     = $product->get_sku();

            $productIds[]   = $product->get_id();
            $productNames[] = $product->get_name();
            $productSkus[]  = empty($sku) ? '–' : $sku;
        }

        $formattedLabelDescription = strtr(
            $this->orderSettings->getLabelDescription(),
            [
                '[DELIVERY_DATE]' => date('d-m-Y', strtotime($this->deliveryOptions->getDate())),
                '[ORDER_NR]'      => $this->order->get_order_number(),
                '[PRODUCT_ID]'    => implode(', ', $productIds),
                '[PRODUCT_NAME]'  => implode(', ', $productNames),
                '[PRODUCT_QTY]'   => count($this->order->get_items()),
                '[PRODUCT_SKU]'   => implode(', ', $productSkus),
                '[CUSTOMER_NOTE]' => $this->order->get_customer_note(),
            ]
        );

        if (strlen($formattedLabelDescription) > WCPN_Export::ORDER_DESCRIPTION_MAX_LENGTH) {
            return substr($formattedLabelDescription, 0, 42) . "...";
        }

        return $formattedLabelDescription;
    }

    /**
     * Set the pickup location
     */
    private function setPickupLocation(): void
    {
        if (! $this->deliveryOptions->isPickup()) {
            return;
        }

        $pickupLocation = $this->deliveryOptions->getPickupLocation();

        $this->consignment
            ->setPickupCountry($pickupLocation->getCountry())
            ->setPickupCity($pickupLocation->getCity())
            ->setPickupLocationName($pickupLocation->getLocationName())
            ->setPickupStreet($pickupLocation->getStreet())
            ->setPickupNumber($pickupLocation->getNumber())
            ->setPickupPostalCode($pickupLocation->getPostalCode())
            ->setRetailNetworkId($pickupLocation->getRetailNetworkId())
            ->setPickupLocationCode($pickupLocation->getLocationCode());
    }

    /**
     * Set the shipment options.
     *
     * @throws Exception
     */
    private function setShipmentOptions(): void
    {
        $this->consignment
            ->setAgeCheck($this->orderSettings->hasAgeCheck())
            ->setInsurance($this->orderSettings->getInsuranceAmount())
            ->setOnlyRecipient($this->orderSettings->hasOnlyRecipient())
            ->setReturn($this->orderSettings->hasReturnShipment())
            ->setSignature($this->orderSettings->hasSignature())
            ->setContents($this->getContents())
            ->setInvoice($this->order->get_id());
    }

    /**
     * Sets a customs declaration for the consignment if necessary.
     *
     * @throws \Exception
     */
    private function setCustomsDeclaration(): void
    {
        $shippingCountry = WCX_Order::get_prop($this->order, "shipping_country");

        if (WCPN_Country_Codes::isWorldShipmentCountry($shippingCountry)) {
            $this->setCustomItems();
        }
    }

    /**
     * Sets a customs declaration for the consignment if necessary.
     *
     * @throws \Exception
     */
    private function setPhysicalProperties(): void
    {
        $extraOptions = WCX_Order::get_meta($this->order, WCPOST_Admin::META_SHIPMENT_OPTIONS_EXTRA);
        $packageType  = $this->getPackageType();

        if (! $extraOptions) {
            return;
        }

        $orderWeight = (int) $this->order->get_meta(WCPOST_Admin::META_ORDER_WEIGHT);
        $totalWeight = $this->getTotalWeight($orderWeight);
        $weight      = $extraOptions['weight'] ?? $this->orderSettings->getWeight();

        if ((float) $orderWeight === $weight) {
            $totalWeight = (new WCPN_Export())->calculatedKiloWeight($totalWeight * 1000);
        }
        $this->consignment->setPhysicalProperties(
            [
                "weight" => round($totalWeight)
            ]
        );
    }

    /**
     * @throws Exception
     */
    private function setBaseData(): void
    {
        $this->consignment
            ->setApiKey($this->apiKey)
            ->setReferenceId((string) $this->order->get_id())
            ->setPackageType($this->getPackageType())
            ->setDeliveryDate($this->getDeliveryDate())
            ->setDeliveryType($this->getDeliveryType())
            ->setLabelDescription($this->getFormattedLabelDescription());
    }
}
