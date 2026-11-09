<?php
declare(strict_types=1);

namespace Lotsofpixels\CmNotificationResync\Console\Command;

use Lotsofpixels\CmNotificationResync\Model\NotificationResyncer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ResyncNotifications extends Command
{
    public function __construct(
        private readonly NotificationResyncer $resyncer,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('lotsofpixels:cm:resync-notifications')
            ->setDescription('Call the CM.com notification webhook (/cmpayments/payment/notification?id=...) for recent orders, one by one')
            ->addOption('days', 'd', InputOption::VALUE_REQUIRED, 'Look back this many days', '7')
            ->addOption('all-methods', null, InputOption::VALUE_NONE, 'Include orders not paid with a CM.com payment method')
            ->addOption('delay', null, InputOption::VALUE_REQUIRED, 'Milliseconds to wait between requests', '250')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only print the URLs, do not call them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (LocalizedException) {
            // area already set
        }

        $days = max(1, (int)$input->getOption('days'));
        $delayMs = max(0, (int)$input->getOption('delay'));
        $dryRun = (bool)$input->getOption('dry-run');

        $orders = $this->resyncer->getOrders($days, !$input->getOption('all-methods'));
        $output->writeln(sprintf('<info>%d order(s) found in the last %d day(s).</info>', count($orders), $days));

        $ok = 0;
        $failed = 0;
        foreach ($orders as $i => $order) {
            $url = $this->resyncer->buildUrl($order['increment_id'], $order['store_id']);

            if ($dryRun) {
                $output->writeln($url);
                continue;
            }

            try {
                $result = $this->resyncer->notify($url);
                $success = $result['status'] >= 200 && $result['status'] < 300;
                $success ? $ok++ : $failed++;
                $output->writeln(sprintf(
                    '%s #%s -> HTTP %d %s',
                    $success ? '<info>OK</info>  ' : '<error>FAIL</error>',
                    $order['increment_id'],
                    $result['status'],
                    $success ? '' : trim(substr($result['body'], 0, 200))
                ));
            } catch (\Throwable $e) {
                $failed++;
                $output->writeln(sprintf('<error>FAIL</error> #%s -> %s', $order['increment_id'], $e->getMessage()));
            }

            if ($delayMs > 0 && $i < count($orders) - 1) {
                usleep($delayMs * 1000);
            }
        }

        if (!$dryRun) {
            $output->writeln(sprintf('<info>Done: %d OK, %d failed.</info>', $ok, $failed));
        }

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
