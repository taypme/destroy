<?php

namespace App\Command;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'destroy',
    description: 'Clone, rewrite, and republish every repository for a GitHub vendor.',
)]
final class VendorCommand extends Command
{
    public function __construct(
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('vendor', InputArgument::REQUIRED, 'GitHub user or organization name');
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, 'Source branch to rewrite', 'master');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Target branch to publish', 'main');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $vendor = trim((string) $input->getArgument('vendor'));
        $fromBranch = trim((string) $input->getOption('from'));
        $toBranch = trim((string) $input->getOption('to'));

        if ($vendor === '') {
            throw new RuntimeException('The vendor argument cannot be empty.');
        }

        if ($fromBranch === '' || $toBranch === '') {
            throw new RuntimeException('The --from and --to options cannot be empty.');
        }

        if ($fromBranch === $toBranch) {
            throw new RuntimeException('The --from and --to options must be different.');
        }

        $repos = $this->fetchRepos($vendor);

        if ($repos === []) {
            $io->warning(sprintf('No repositories found for "%s".', $vendor));

            return Command::SUCCESS;
        }

        $io->title(sprintf('Repositories for %s', $vendor));

        foreach ($repos as $repo) {
            $io->writeln(sprintf('<info>%s</info>', $repo['nameWithOwner']));
            $io->writeln('');
        }

        if (!$io->confirm('Proceed with the destructive rewrite for every repository listed above?', false)) {
            $io->warning('Aborted.');

            return Command::SUCCESS;
        }

        $tmpDir = $this->projectDir.'/tmp';
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
            throw new RuntimeException(sprintf('Unable to create tmp directory at "%s".', $tmpDir));
        }

        foreach ($repos as $repo) {
            $this->processRepository($repo, $tmpDir, $fromBranch, $toBranch, $io);
        }

        $io->success('Finished.');

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array{nameWithOwner: string, name: string, sshUrl: string}>
     */
    private function fetchRepos(string $vendor): array
    {
        $output = $this->runProcess([
            'gh',
            'repo',
            'list',
            $vendor,
            '--limit',
            '1000',
            '--json',
            'nameWithOwner,name,sshUrl',
        ]);

        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new RuntimeException('Unexpected response from gh repo list.');
        }

        $repos = [];
        foreach ($decoded as $repo) {
            if (!is_array($repo)) {
                continue;
            }

            $nameWithOwner = (string) ($repo['nameWithOwner'] ?? '');
            $name = (string) ($repo['name'] ?? '');
            $sshUrl = (string) ($repo['sshUrl'] ?? '');

            if ($nameWithOwner === '' || $name === '' || $sshUrl === '') {
                continue;
            }

            $repos[] = [
                'nameWithOwner' => $nameWithOwner,
                'name' => $name,
                'sshUrl' => $sshUrl,
            ];
        }

        usort($repos, static fn (array $left, array $right): int => $left['nameWithOwner'] <=> $right['nameWithOwner']);

        return $repos;
    }

    /**
     * @param array{nameWithOwner: string, name: string, sshUrl: string} $repo
     */
    private function processRepository(array $repo, string $tmpDir, string $fromBranch, string $toBranch, SymfonyStyle $io): void
    {
        $repoDir = $tmpDir.'/'.$repo['name'];
        $filesystem = new Filesystem();

        if (is_dir($repoDir)) {
            $filesystem->remove($repoDir);
        }

        $io->section(sprintf('Processing %s', $repo['nameWithOwner']));

        try {
            $this->runProcess([
                'gh',
                'repo',
                'clone',
                $repo['nameWithOwner'],
                $repoDir,
            ]);

            $tempBranch = '__destroy_tmp__';
            $this->runProcess(['git', 'checkout', '--orphan', $tempBranch], $repoDir);
            $this->runProcess(['git', 'add', '-A'], $repoDir);

            $commitIdentity = $this->resolveCommitIdentity($repoDir);
            $commitEnv = [
                'GIT_AUTHOR_NAME' => $commitIdentity['name'],
                'GIT_AUTHOR_EMAIL' => $commitIdentity['email'],
                'GIT_COMMITTER_NAME' => $commitIdentity['name'],
                'GIT_COMMITTER_EMAIL' => $commitIdentity['email'],
                'GIT_AUTHOR_DATE' => '1992-06-09T09:30:00-06:00',
                'GIT_COMMITTER_DATE' => '1992-06-09T09:30:00-06:00',
            ];

            $this->runProcess([
                'git',
                'commit',
                '--allow-empty',
                '-m',
                '---',
            ], $repoDir, $commitEnv);

            $this->runProcess([
                'git',
                'checkout',
                '-B',
                $toBranch,
            ], $repoDir);

            $this->runProcess([
                'git',
                'push',
                '--force',
                '--set-upstream',
                'origin',
                sprintf('%s:%s', $toBranch, $toBranch),
            ], $repoDir);

            $this->setDefaultBranch($repo['nameWithOwner'], $toBranch);
            $this->deleteOtherBranches($repo['nameWithOwner'], $toBranch, $io);

            $io->writeln(sprintf('<info>Rewritten</info> %s (%s -> %s)', $repo['nameWithOwner'], $fromBranch, $toBranch));
        } finally {
            $filesystem->remove($repoDir);
        }
    }

    /**
     * @return array{name: string, email: string}
     */
    private function resolveCommitIdentity(string $repoDir): array
    {
        $name = trim($this->runProcess(['git', 'config', '--get', 'user.name'], $repoDir, [], true));
        $email = trim($this->runProcess(['git', 'config', '--get', 'user.email'], $repoDir, [], true));

        if ($name !== '' && $email !== '') {
            return [
                'name' => $name,
                'email' => $email,
            ];
        }

        $login = trim($this->runProcess([
            'gh',
            'api',
            'user',
            '--jq',
            '.login',
        ]));

        if ($login === '') {
            throw new RuntimeException('Unable to resolve a Git author identity.');
        }

        return [
            'name' => $name !== '' ? $name : $login,
            'email' => $email !== '' ? $email : $login.'@users.noreply.github.com',
        ];
    }

    private function setDefaultBranch(string $repoNameWithOwner, string $toBranch): void
    {
        $this->runProcess([
            'gh',
            'repo',
            'edit',
            $repoNameWithOwner,
            '--default-branch',
            $toBranch,
        ]);
    }

    private function deleteBranch(string $repoNameWithOwner, string $branchName): void
    {
        try {
            $this->runProcess([
                'gh',
                'api',
                '--method',
                'DELETE',
                sprintf('repos/%s/git/refs/heads/%s', $repoNameWithOwner, rawurlencode($branchName)),
            ]);
        } catch (RuntimeException $exception) {
            if (!$this->looksLikeMissingRef($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function fetchBranchNames(string $repoNameWithOwner): array
    {
        $output = $this->runProcess([
            'gh',
            'api',
            sprintf('repos/%s/branches?per_page=100', $repoNameWithOwner),
            '--paginate',
            '--jq',
            '.[].name',
        ]);

        $branchNames = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '') {
                $branchNames[] = $name;
            }
        }

        return array_values(array_unique($branchNames));
    }

    private function deleteOtherBranches(string $repoNameWithOwner, string $keepBranchName, SymfonyStyle $io): void
    {
        $branchNames = $this->fetchBranchNames($repoNameWithOwner);
        $failures = [];

        foreach ($branchNames as $branchName) {
            if ($branchName === $keepBranchName) {
                continue;
            }

            try {
                $this->deleteBranch($repoNameWithOwner, $branchName);
                $io->writeln(sprintf('<comment>Deleted branch</comment> %s', $branchName));
            } catch (RuntimeException $exception) {
                if ($this->looksLikeMissingRef($exception)) {
                    continue;
                }

                $failures[] = sprintf('%s: %s', $branchName, $exception->getMessage());
                $io->warning(sprintf('Failed to delete branch %s on %s', $branchName, $repoNameWithOwner));
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                "Failed to delete one or more branches on %s:\n%s",
                $repoNameWithOwner,
                implode("\n", $failures),
            ));
        }
    }

    /**
     * @param array<int, string> $command
     * @param array<string, string> $env
     */
    private function runProcess(array $command, ?string $cwd = null, array $env = [], bool $allowFailure = false): string
    {
        $process = new Process($command, $cwd, $env);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful() && !$allowFailure) {
            throw new RuntimeException(sprintf(
                "Command failed: %s\n\n%s",
                implode(' ', $command),
                trim($process->getErrorOutput() !== '' ? $process->getErrorOutput() : $process->getOutput()),
            ));
        }

        return $process->getOutput();
    }

    private function looksLikeMissingRef(RuntimeException $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Not Found')
            || str_contains($message, 'could not resolve to a RepositoryRef')
            || str_contains($message, 'Reference does not exist');
    }

}
