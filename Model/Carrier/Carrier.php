<?php
/**
 * Set MyParcel Shipping methods
 *
 * LICENSE: This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 *
 * If you want to add improvements, please create a fork in our GitHub:
 * https://github.com/myparcelnl
 *
 * @author      Reindert Vetter <info@myparcel.nl>
 * @copyright   2010-2019 MyParcel
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US  CC BY-NC-ND 3.0 NL
 * @link        https://github.com/myparcelnl/magento
 * @since       File available since Release 0.1.0
 */

namespace MyParcelNL\Magento\Model\Carrier;

use Composer\Config;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Xml\Security;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use MyParcelNL\Magento\Model\Sales\Repository\PackageRepository;
use MyParcelNL\Magento\Service\Config\ConfigService;
use MyParcelNL\Magento\Service\Costs\DeliveryCostsService;
use MyParcelNL\Sdk\src\Adapter\DeliveryOptions\DeliveryOptionsV3Adapter;
use MyParcelNL\Sdk\src\Factory\DeliveryOptionsAdapterFactory;
use Psr\Log\LoggerInterface;

class Carrier extends AbstractCarrier implements CarrierInterface
{
    const CODE = 'myparcelnl_delivery';

    protected $_code = self::CODE; // $_code is a mandatory property for Magento carrier
    protected $_freeShipping;


    /**
     * @var PackageRepository
     */
    private $package;

    /**
     * Carrier constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface          $scopeConfig
     * @param \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory  $rateErrorFactory
     * @param \Psr\Log\LoggerInterface                                    $logger
     * @param Security                                                    $xmlSecurity
     * @param \Magento\Shipping\Model\Simplexml\ElementFactory            $xmlElFactory
     * @param \Magento\Shipping\Model\Rate\ResultFactory                  $rateFactory
     * @param \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory
     * @param \Magento\Shipping\Model\Tracking\ResultFactory              $trackFactory
     * @param \Magento\Shipping\Model\Tracking\Result\ErrorFactory        $trackErrorFactory
     * @param \Magento\Shipping\Model\Tracking\Result\StatusFactory       $trackStatusFactory
     * @param \Magento\Directory\Model\RegionFactory                      $regionFactory
     * @param \Magento\Directory\Model\CountryFactory                     $countryFactory
     * @param \Magento\Directory\Model\CurrencyFactory                    $currencyFactory
     * @param \Magento\Directory\Helper\Data                              $directoryData
     * @param \Magento\CatalogInventory\Api\StockRegistryInterface        $stockRegistry
     * @param \Magento\Checkout\Model\Session                             $session
     * @param PackageRepository                                           $package
     * @param array                                                       $data
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ConfigService $configService,
        DeliveryCostsService $deliveryCostsService,
        \Magento\Shipping\Model\Rate\ResultFactory $rateFactory,
        MethodFactory $rateMethodFactory,
        Session $session,
        PackageRepository $package,
        \Magento\OfflineShipping\Model\Carrier\Freeshipping $freeShipping,
        array $data = []
    ) {
        parent::__construct(
            $scopeConfig,
            $rateErrorFactory,
            $logger,
            $data,
        );
        //$this->quote = $session->getQuote();
        try {
            $this->deliveryOptions = DeliveryOptionsAdapterFactory::create(json_decode($session->getQuote()->getData('myparcel_delivery_options'), true));
        } catch (\Throwable $e) {
            $this->deliveryOptions = DeliveryOptionsAdapterFactory::create(DeliveryOptionsV3Adapter::DEFAULTS);
        }
        $this->package = $package;
        $this->rateResultFactory = $rateFactory;
        $this->rateMethodFactory = $rateMethodFactory;
        $this->_freeShipping = $freeShipping;
        $this->configService  = $configService;
        $this->deliveryCostsService = $deliveryCostsService;
    }

    protected function _doShipmentRequest(DataObject $request)
    {
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $result = $this->rateResultFactory->create();
        $method = $this->rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle('MyParcel');
        $method->setMethod('MyParcel');
        $method->setMethodTitle($this->getMethodTitle());
        $method->setPrice((string) $this->getMethodAmount());

        $result->append($method);

        return $result;
    }

    private function getMethodAmount(): float {
        $path = ConfigService::CARRIERS_XML_PATH_MAP[$this->deliveryOptions->getCarrier()]??'';
        $dinges = [
            "{$this->deliveryOptions->getDeliveryType()}/fee"=>true,
            "{$this->deliveryOptions->getPackageType()}/fee"=>true,
            'delivery/signature_fee'=> $this->deliveryOptions->getShipmentOptions()->hasSignature(),
            'delivery/only_recipient_fee'=> $this->deliveryOptions->getShipmentOptions()->hasOnlyRecipient(),
        ];
        $amount = $this->deliveryCostsService->getBasePrice();
        foreach ($dinges as $key=>$value) {
            //echo $key, '  ', $value, ': ',$this->myParcelHelper->getCarrierConfig($key, $path), PHP_EOL;
            if (! $value) continue;
            $amount += (float) $this->configService->getConfigValue("$path$key");
        }
        //$bla = $this->myParcelHelper->getCarrierConfig('evening/fee', $path);
        return $amount;
        die(' weflweiuryfuhj');
        //$this->configService->getMethodPrice(ConfigService::XML_PATH_POSTNL_SETTINGS . 'fee', $alias);
        $freeShippingIsAvailable = false; // todo // $this->_freeShipping->getConfigData('active')
        // todo: get actual price based on chosen and possible options for this quote / cart
        $amount = $freeShippingIsAvailable ? 0.00 : 10.00;
return $amount;
    }

    private function getMethodTitle(): string {
        // todo make a nice title from the options
        return var_export($this->deliveryOptions, true);
    }

    public function proccessAdditionalValidation(DataObject $request)
    {
        return true;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public static function getMethods()
    {
        $methods = [
            'signature_only_recip' => 'delivery/signature_and_only_recipient_',
            'morning'              => 'morning/',
            'morning_signature'    => 'morning_signature/',
            'evening'              => 'evening/',
            'evening_signature'    => 'evening_signature/',
            'pickup'               => 'pickup/',
            'mailbox'              => 'mailbox/',
            'digital_stamp'        => 'digital_stamp/',
        ];

        return $methods;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods(): array
    {
        return self::getMethods();
    }

//    /**
//     * @param \Magento\Quote\Model\Quote\Address\RateRequest $result
//     * @return mixed
//     */
//    private function addShippingMethods($result)
//    {
//        $this->package->setDigitalStampSettings();
//        $this->package->setMailboxSettings();
//
//        foreach ($this->getAllowedMethods() as $alias => $settingPath) {
//            $active = $this->configService->getConfigValue(ConfigService::XML_PATH_POSTNL_SETTINGS . $settingPath . 'active') === '1';
//            if ($active) {
//                $method = $this->getShippingMethod($alias, $settingPath);
//                $result->append($method);
//            }
//        }
//
//        return $result;
//    }

    /**
     * @param $alias
     * @param  string $settingPath
     *
     * @return \Magento\Quote\Model\Quote\Address\RateResult\Method
     */
    private function getShippingMethod($alias, string $settingPath)
    {
        $title = $this->createTitle($settingPath);
        $price = $this->createPrice($alias, $settingPath);

        $method = $this->rateMethodFactory->create();
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($alias);
        $method->setMethod($alias);
        $method->setMethodTitle($title);
        $method->setPrice($price);

        return $method;
    }

    /**
     * Create title for method
     * If no title isset in config, get title from translation
     *
     * @param $settingPath
     * @return \Magento\Framework\Phrase|mixed
     */
    private function createTitle($settingPath)
    {
        $title = $this->configService->getConfigValue(ConfigService::XML_PATH_POSTNL_SETTINGS . $settingPath . 'title');

        if ($title === null) {
            $title = __($settingPath . 'title');
        }

        return $title;
    }

    /**
     * Create price
     * Calculate price if multiple options are chosen
     *
     * @param $alias
     * @param $settingPath
     * @return float
     */
    private function createPrice($alias, $settingPath)
    {
        if ($this->_freeShipping->getConfigData('active')) {
            return 0;
        }

        return 10 + $this->configService->getMethodPrice($settingPath . 'fee', $alias);
    }

    public function isTrackingAvailable(): bool
    {
        // TODO: Implement isTrackingAvailable() method.
        return true;
    }
}
