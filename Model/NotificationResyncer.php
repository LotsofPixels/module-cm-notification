<?php
declare(strict_types=1);

namespace Lotsofpixels\CmNotificationResync\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class NotificationResyncer
{
    private const NOTIFICATION_PATH = 'cmpayments/payment/notification';

    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly CurlFactory $curlFactory
    ) {
    }

    /**
     * Order increment IDs (with store id) created in the last $days days.
     *
     * @return array<int, array{increment_id: string, store_id: int}>
     * @throws \DateMalformedStringException
     */
    public function getOrders(int $days, bool $cmOnly = true): array
    {
        $from = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', $days))
            ->format('Y-m-d H:i:s');

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToSelect(['increment_id', 'store_id'])
            ->addFieldToFilter('main_table.created_at', ['gteq' => $from])
            ->setOrder('main_table.created_at', 'ASC');

        if ($cmOnly) {
            $collection->getSelect()->join(
                ['payment' => $collection->getTable('sales_order_payment')],
                'payment.parent_id = main_table.entity_id',
                []
            )->where('payment.method LIKE ?', 'cm_payments%');
        }

        $orders = [];
        foreach ($collection->getData() as $row) {
            $orders[] = [
                'increment_id' => (string)$row['increment_id'],
                'store_id' => (int)$row['store_id'],
            ];
        }

        return $orders;
    }

    public function buildUrl(string $incrementId, int $storeId): string
    {
        $baseUrl = $this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_LINK);

        return rtrim($baseUrl, '/') . '/' . self::NOTIFICATION_PATH . '?id=' . rawurlencode($incrementId);
    }

    /**
     * Call the CM notification webhook for one order.
     *
     * @return array{status: int, body: string}
     */
    public function notify(string $url, int $timeout = 30): array
    {
        $curl = $this->curlFactory->create();
        $curl->setTimeout($timeout);
        $curl->setOption(CURLOPT_FOLLOWLOCATION, true);
        $curl->get($url);

        return ['status' => (int)$curl->getStatus(), 'body' => (string)$curl->getBody()];
    }
}
