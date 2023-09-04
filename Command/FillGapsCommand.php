<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\PbariccoBundle\Command;

use App\Entity\Activity;
use App\Entity\Timesheet;
use App\Export\ServiceExport;
use App\Repository\CustomerRateRepository;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use App\Repository\Query\ActivityQuery;
use App\Repository\Query\ProjectQuery;
use App\Repository\Query\TimesheetQuery;
use App\Repository\TimesheetRepository;
use App\Repository\UserRepository;
use App\Repository\ActivityRepository;
use App\User\LoginManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;

/** sudo -u www-data php bin/console pbaricco:fill-gaps pietro --customer_id=2 --day=2023-01-18 */
class FillGapsCommand extends Command
{
    /** @var TimesheetRepository */
    protected $repository;

    /** @var ProjectRepository */
    protected $projectRepository;

    /** @var UserRepository */
    protected $userRepository;

    /** @var ServiceExport */
    protected $exportService;

    /** @var LoginManager */
    protected $loginManager;

    /** @var MailerInterface */
    protected $mailer;

    private ?CustomerRepository $customerRepository;
    private ?CustomerRateRepository $customerRateRepository;
    private ?ActivityRepository $activityRepository;

    public function __construct(
        string $name = null,
        ?TimesheetRepository $repository = null,
        ?ProjectRepository $projectRepository = null,
        ?UserRepository $userRepository = null,
        ?CustomerRepository $customerRepository,
        ?CustomerRateRepository $customerRateRepository,
        ?ActivityRepository $activityRepository,
        ?ServiceExport $exportService = null,
        ?LoginManager $loginManager = null,
        ?MailerInterface $mailer = null
    ) {
        $this->repository = $repository;
        $this->projectRepository = $projectRepository;
        $this->userRepository = $userRepository;
        $this->customerRepository = $customerRepository;
        $this->customerRateRepository = $customerRateRepository;
        $this->activityRepository = $activityRepository;
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
            ->setName('pbaricco:fill-gaps')
            ->setDescription('Fills the gaps, because laziness')
            ->addArgument('user', InputArgument::REQUIRED)
            ->addOption('day', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD')
            ->addOption('refresh', null, InputOption::VALUE_OPTIONAL, 'If set to 1, refreshes the git repos (pull)')
        ;

        parent::configure();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // like 3/1/2019, but not 03/01/2019
        $dateFormat = 'j/n/Y';

        $day = $input->getOption('day');
        $userName = $input->getArgument('user');
        $refresh = $input->getOption('refresh');

        $reposMatrix = [
            1 => [
                    'git@github.com:oxygloo/spada.git',
                    'git@bitbucket.org:swagou/platform-dashboard.git'
            ],
            2 => [
                    'git@bitbucket.org:intrumitalydevs/mis-app.git',
                    'git@bitbucket.org:intrumitalydevs/mis-fresh.git',
                    'git@bitbucket.org:cafitdreamteam/docfiler.git',
                    'git@bitbucket.org:cafitdreamteam/pdi-mis-shared.git',
            ],
            3 => [
                    'git@github.com:oxygloo/spada.git',
                    'git@bitbucket.org-axisadvisors:axis-advisors/app.git'
            ]
        ];

        if ($refresh == 1) {
            $this-> refreshGitRepos($reposMatrix);
        }

        $user = $this->userRepository->loadUserByUsername($userName);
        $this->loginManager->logInUser($user, new Response());

        $query = new TimesheetQuery();
        $query->setUser($user);

        $startDate = new \DateTime($day);

        // devel
        $activity = $this->activityRepository->find(2);

        // For simplicity, we'll use simple readline() for input:
        while (true) {
            $endDate = clone $startDate;
            $startDate->setTime(0, 0);
            $endDate->setTime(23, 59, 59);
            $query->setBegin($startDate);
            $query->setEnd($endDate);

            // clear screen
            echo "\033[2J\033[;H";
            $output->writeln($startDate->format($dateFormat));

            $dayOfWeek = $startDate->format('N');
            // if weekend, print it
            $fg = $dayOfWeek > 5 ? 'red' : 'green';
            // to readable format
            $dayOfWeek = $dayOfWeek == 1 ? 'Monday' : ($dayOfWeek == 2 ? 'Tuesday' : ($dayOfWeek == 3 ? 'Wednesday' : ($dayOfWeek == 4 ? 'Thursday' : ($dayOfWeek == 5 ? 'Friday' : ($dayOfWeek == 6 ? 'Saturday' : 'Sunday')))));
            $output->writeln("<fg=$fg>$dayOfWeek</>");
            $totalHours = 0;

            foreach ($reposMatrix as $customerId => $gitRepos) {
                $customers = $this->customerRepository->findByIds([$customerId]);
                $customerName = $customers[0]->getName();
                $query->setCustomers($customers);

                $projectQuery = new ProjectQuery();
                $projectQuery->setCustomers($customers);
                $firstProject = $this->projectRepository->getProjectsForQuery($projectQuery)[0] ?? null;

                $output->writeln("\n\n<fg=magenta>$customerId</>: <fg=cyan>$customerName</>, Default project: <fg=yellow>" . ($firstProject ? $firstProject->getName() : '') . '</>');

                $timeSheets = $this->repository->getTimesheetsForQuery($query);
                $commits = $this->getGitCommits($gitRepos, $startDate, $endDate);

                $table = new Table($output);
                $table->setHeaders([
                    'Commits',
                    'Timesheets'
                ]);
                $table->setColumnWidths([140, 140]);

                $timeSheetsCol = array_map(
                    fn (Timesheet $ts) => implode(' - ', [
                        str_pad(round($ts->getDuration() / 3600, 2) . ' h', 10),
                        str_pad($ts->getBegin()->format('H:i'), 5),
                        $ts->getDescription(),
                        $ts->getActivity()->getName()
                    ]),
                    $timeSheets
                );

                $totalTimeSheets = array_reduce(
                    $timeSheets,
                    fn ($carry, Timesheet $ts) => $carry + $ts->getDuration(),
                    0
                );

                $targetHours = 8;

                // print the total hours, colored: red < 50%, yellow < 80%, green >= 80%
                // use outputinterface->writeln and the color constants <fg=red> <fg=yellow> <fg=green>

                $customerHours = round($totalTimeSheets / 3600, 2);
                $totalHours += $customerHours;
                $color = $customerHours < $targetHours * 0.5 ? 'red' : ($customerHours < $targetHours * 0.8 ? 'yellow' : 'green');
                $output->writeln("Total hours: <fg=$color>$customerHours</>");

                $commitsCol = array_map(
                    fn (array $commit) => implode(' - ', [
                        str_pad($commit['repo'], 20),
                        $commit['author'],
                        $commit['date'],
                        $commit['message']
                    ]),
                    $commits
                );

                $data = [];
                $max = max(\count($timeSheetsCol), \count($commitsCol));
                for ($i = 0; $i < $max; $i++) {
                    $data[] = [
                        trim($commitsCol[$i] ?? ''),
                        trim($timeSheetsCol[$i] ?? '')
                    ];
                }

                $table->setRows($data);
                $table->render();
            }

            $output->writeln("\n\n<fg=green>Total hours: $totalHours</>\n");

            $inputValue = readline('(n)ext (p)revious (q)uit (a)dd:');
            switch ($inputValue) {
                case 'n': $startDate->add(new \DateInterval('P1D')); break;
                case 'p': $startDate->sub(new \DateInterval('P1D')); break;
                case 'a':
                    // read customer id
                    $customerId = readline('Customer id: ');
                    $customers = $this->customerRepository->findByIds([$customerId]);
                    $customer = $customers[0] ?? null;
                    if (!$customer) {
                        echo "Invalid customer id\n";
                        break;
                    }
                    // fetch the default rate
                    $rate = $this->customerRateRepository->getRatesForCustomer($customer)[0] ?? null;

                    // select project
                    $pjq = new ProjectQuery();
                    $pjq->setCustomers($customers);
                    $projects = $this->projectRepository->getProjectsForQuery($pjq);
                    if (\count($projects) == 1) {
                        $projectIndex = 0;
                    } else {
                        $output->writeln('Select project:');
                        foreach ($projects as $k => $project) {
                            $output->writeln("$k: {$project->getName()}");
                        }
                        $projectIndex = readline('Project index: ');
                    }
                    /** @var TYPE_NAME $project */
                    $project = $projects[$projectIndex] ?? null;
                    if (!$project) {
                        echo "Invalid project index\n";
                        break;
                    }

                    // other fields
                    $duration = readline('Duration (in hours): ');
                    $description = readline('Description: ');

                    // print a resume of the timesheet
                    $output->writeln("Customer: <fg=cyan>{$customer->getName()}</>");
                    $output->writeln("Project: <fg=yellow>{$project->getName()}</>");
                    $output->writeln("Duration: <fg=green>$duration</>");
                    $output->writeln("Description: <fg=green>$description</>");

                    $inputValue = readline('Confirm? (y/n): ');
                    if ($inputValue !== 'y') {
                        break;
                    }

                    $timeSheetStartDate = clone $startDate;
                    $timeSheetStartDate->setTime(rand(8, 11), 0);

                    $timesheet = new Timesheet();
                    $timesheet
                        ->setUser($user)
                        ->setActivity($activity)
                        ->setProject($project)
                        ->setBegin($timeSheetStartDate)
                        ->setDuration($duration * 3600)
                        ->setDescription($description)
                        ->setHourlyRate($rate ? $rate->getRate() : 0)
                        ->setBillable(true)
                        ->setCategory('work');

                    $this->repository->save($timesheet);

                    $output->writeln('Timesheet saved');
                    sleep(2);
                    break;

                case 'q': return 0;
                default: echo "Invalid input\n: $inputValue"; break;
            }
        }

        return 0;
    }

    /**
     * returns the git commits for the given repository, from the given date to the given date
     * returns an array of elements, each element is an array like
     * [
     *  'hash' => '0123456789abcdef',
     *  'date' => '2022-02-02 12:12:12
     *  'author' => 'John Doe',
     *  'message' => 'This is a commit message'
     * ]
     * @param string $gitRepositoryPath
     * @return array
     */
    protected function getGitCommits(array $gitRepos, \DateTime $from, \DateTime $to): array
    {
        $ret = [];

        foreach ($gitRepos as $gitRepo) {
            $gitRepositoryPath = '/tmp/' . md5($gitRepo);

            $gitRepoName = explode('/', $gitRepo);
            $gitRepoName = end($gitRepoName);

            $cmd = "cd $gitRepositoryPath && git log --pretty=format:'%H|%ad|%an|%s' --date=format:'%H:%M' --after='{$from->format('Y-m-d 00:00:00')}' --before='{$to->format('Y-m-d 23:59:59')}'";
            // echo "Executing $cmd\n";
            $gitLog = shell_exec($cmd);
            $gitLog = explode("\n", $gitLog);
            foreach ($gitLog as $logLine) {
                $logLine = explode('|', $logLine);
                if (\count($logLine) === 4) {
                    $ret[] = [
                        'repo' => $gitRepoName,
                        'hash' => trim($logLine[0]),
                        'date' => trim($logLine[1]),
                        'author' => trim($logLine[2]),
                        'message' => trim($logLine[3])
                    ];
                }
            }
        }

        return $ret;
    }

    protected function refreshGitRepos(array $reposMatrix)
    {
        foreach ($reposMatrix as $customerId => $gitRepos) {
            foreach ($gitRepos as $gitRepo) {
                echo "Refreshing $gitRepo\n";
                $gitRepositoryPath = '/tmp/' . md5($gitRepo);
                if (!file_exists($gitRepositoryPath)) {
                    passthru("git clone $gitRepo $gitRepositoryPath");
                } else {
                    passthru("cd $gitRepositoryPath && git pull");
                }
            }
        }
    }
}
