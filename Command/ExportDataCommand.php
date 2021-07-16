<?php

namespace KimaiPlugin\PbariccoBundle\Command;

use App\Export\ServiceExport;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\User\LoginManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class ExportDataCommand extends Command
{
    /** @var TimesheetRepository */
    protected $repository;

    /** @var UserRepository */
    protected $userRepository;

    /** @var ServiceExport */
    protected $exportService;

    /** @var LoginManager */
    protected $loginManager;

    /** @var MailerInterface */
    protected $mailer;

    public function __construct(
        string $name = null,
        ?TimesheetRepository $repository = null,
        ?UserRepository $userRepository = null,
        ?ServiceExport $exportService = null,
        ?LoginManager $loginManager = null,
        ?MailerInterface $mailer = null
    ) {
        $this->repository = $repository;
        $this->userRepository = $userRepository;
        $this->exportService = $exportService;
        $this->loginManager = $loginManager;
        $this->mailer = $mailer;
        return parent::__construct($name);
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('kimai:export:timesheet')
            ->setDescription('Exports timesheet data from commandline')
            ->addArgument('user', InputArgument::REQUIRED)
            ->addArgument('format', InputArgument::REQUIRED)
            ->addOption('customer_id', null, InputOption::VALUE_OPTIONAL)
            ->addOption('period', null, InputOption::VALUE_OPTIONAL, 'supports: week,month,week-1,week-n,month-1,month-n')
            ->addOption('target_file', null, InputOption::VALUE_OPTIONAL, 'optional, if set, saves data locally to the specified file')
            ->addOption('hide_rates', null, InputOption::VALUE_OPTIONAL, 'hides all the rates columns')
            ->addOption('custom_title', null, InputOption::VALUE_OPTIONAL, 'adds this string in the email subject')
            ->addOption('email_to', null, InputOption::VALUE_OPTIONAL, 'if set, data is sent via email')
        ;

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $userName = $input->getArgument('user');
        $format = $input->getArgument('format');
        $customerId = $input->getOption('customer_id');
        $period = $input->getOption('period');
        $targetFile = $input->getOption('target_file');
        $hideRates = !!$input->getOption('hide_rates');
        $customTitle = $input->getOption('custom_title');
        $emailTo = $input->getOption('email_to');

        $dateFormat = 'd/m/Y';

        $user = $this->userRepository->loadUserByUsername($userName);
        $this->loginManager->logInUser($user, new Response());

        $query = new TimesheetQuery();
        $query->setUser($user);

        $startDate = new \DateTime();
        $endDate = new \DateTime();

        if($period) {
            if(preg_match('/^week(|-([0-9]+))$/sim', $period, $m)) {
                $weeksToSubtract = $m[2] ?? 0;
                $startDate->modify("monday -".($weeksToSubtract+1)." week");
                $endDate->modify("sunday -$weeksToSubtract week");
            }
            if(preg_match('/^month(|-([0-9]+))$/sim', $period, $m)) {
                $monthToSubtract = $m[2] ?? 0;
                $startDate->modify("first day of -$monthToSubtract month");
                $endDate->modify("last day of -$monthToSubtract month");
            }
        }

        $startDate->setTime(0, 0);
        $endDate->setTime(23, 59, 59);
        $query->setBegin($startDate);
        $query->setEnd($endDate);

        if($customerId)
            $query->setCustomers([$customerId]);

        $title = "$customTitle Timesheet data from ".$startDate->format($dateFormat)." to ".$endDate->format($dateFormat);
        $output->writeln("Exporting $title");

        $entries = $this->repository->getTimesheetsForQuery($query);

        if($hideRates) {
            foreach ($entries as $entry) {
                $entry->setRate(0)->setInternalRate(0)->setFixedRate(0)->setHourlyRate(0);
            }
        }

        $exporter = $this->exportService->getTimesheetExporterById($format);

        $ret = $exporter->render($entries, $query);

        $tempFile = $ret->getFile()->getRealPath();

        if($emailTo) {
            $email = (new Email())
                ->from($_ENV['MAILER_FROM'])
                ->to($emailTo)
                ->subject($title)
                ->text("Data is attached in $format format")
                ->attachFromPath($tempFile, 'Timesheet_'.$startDate->format('d_m_Y').'-'.$endDate->format('d_m_Y').".".$format);

            $this->mailer->send($email);
        }

        if($targetFile) {
            rename($tempFile, $targetFile);
        } else @unlink($tempFile);

        return 0;
    }
}
