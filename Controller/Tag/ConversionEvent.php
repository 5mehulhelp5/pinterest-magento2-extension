<?php

namespace Pinterest\PinterestMagento2Extension\Controller\Tag;

use Pinterest\PinterestMagento2Extension\Helper\PinterestHelper;
use Pinterest\PinterestMagento2Extension\Helper\EventIdGenerator;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use \Magento\Framework\App\Action\Action;

class ConversionEvent extends Action
{
    /**
     * @var PinterestHelper
     */
    protected $_pinterestHelper;

    /**
     * @var JsonFactory
     */
    protected $_resultJsonFactory;

    /**
     * Product info for Add to Cart constructor
     *
     * @param PinterestHelper $pinterestHelper
     * @param Context $context
     * @param JsonFactory $resultJsonFactory
     */
    public function __construct(
        PinterestHelper $pinterestHelper,
        Context $context,
        JsonFactory $resultJsonFactory
    ) {
        parent::__construct($context);
        $this->_pinterestHelper = $pinterestHelper;
        $this->_resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Send add to cart data to conversion API and dispatch event
     *
     * @return array
     */
    public function execute()
    {
        try {
            if (!$this->_pinterestHelper->isUserOptedOutOfTracking()) {
                $response_data = [];
                $event_id = EventIdGenerator::guidv4();
                $response_data["event_id"] = $event_id;
                $event_name = $this->getRequest()->getParam("event_name", null);
                $data = $this->getRequest()->getParam("data", null);
                if ($event_name) {
                    switch ($event_name) {
                        case 'page_visit':
                            if ($data && array_key_exists("productData", $data)
                                      && array_key_exists("currency", $data)) {
                                $this->trackPageVisitEvent($event_id, $data["productData"], $data["currency"]);
                            } else {
                                $this->trackPageVisitEvent($event_id);
                            }
                            break;
                        case 'search':
                            $this->trackSearchEvent($event_id, $data["search_query"]);
                            break;
                        case 'view_category':
                            $this->trackViewCategoryEvent($event_id, $data["productDetails"], $data["currency"]);
                            break;
                        default:
                    }
                }
                // Send data back to Tag event sender
                $result = $this->_resultJsonFactory->create();
                $result->setData(array_filter($response_data));
                return $result;
            }
        } catch (\Exception $e) {
            $this->_pinterestHelper->logException($e);
        }
    }

    /**
     * Dispatch the page visit event with the required data
     *
     * @param string $eventId
     * @param array $productDetails
     * @param string $currency
     */
    public function trackPageVisitEvent($eventId, $productDetails = null, $currency = null)
    {
        if ($productDetails && $currency) {
            $custom_data = [
                "content_ids" => [$productDetails["product_id"]],
                "contents" => [[
                    "item_price" => (string) ($productDetails["product_price"])
                ]],
                "currency" => $currency,
            ];
        } else {
            $custom_data = [];
        }

        $this->_eventManager->dispatch("pinterest_commereceintegrationextension_page_visit_after", [
            "event_id" => $eventId,
            "event_name" => "page_visit",
            "custom_data" => $custom_data,
        ]);
    }
    
    /**
     * Dispatch the search event with the required data
     *
     * @param string $eventId
     * @param string $searchTerm
     */
    public function trackSearchEvent($eventId, $searchTerm)
    {
        $this->_eventManager->dispatch(
            "pinterest_commereceintegrationextension_search_after",
            [
                "event_id" => $eventId,
                "event_name" => "search",
                "custom_data" => [
                    "search_string" => $searchTerm
                ],
            ]
        );
    }

    /**
     * Dispatch the view category event with the required data
     *
     * @param string $eventId
     * @param array $productDetails
     * @param string $currency
     */
    public function trackViewCategoryEvent($eventId, $productDetails, $currency)
    {
        $this->_eventManager->dispatch("pinterest_commereceintegrationextension_view_category_after", [
            "event_id" => $eventId,
            "event_name" => "view_category",
            "custom_data" => [
                "currency" => $currency,
                "content_ids" => $productDetails["content_ids"],
                "contents" => $productDetails["contents"],
                "content_category" => $productDetails["category"]
            ],
        ]);
    }
}
