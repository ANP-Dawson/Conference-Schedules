<?php

// SPDX-License-Identifier: Apache-2.0

namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * fwconsole entry for the Conference Schedules module. Phase 1 ships
 * `conferenceschedules:tick`; future subcommands plug in via the same class.
 */
class Conferenceschedules extends Command
{
    protected function configure()
    {
        $this->setName('conferenceschedules:tick')
             ->setDescription(_('Fire conference schedule jobs whose next_fire_utc has elapsed'))
             ->addOption(
                 'dry-run',
                 null,
                 InputOption::VALUE_NONE,
                 _('Log due jobs without dispatching AMI Originate calls')
             );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $bmo    = \FreePBX::Create()->Conferenceschedules;
        $dryRun = (bool) $input->getOption('dry-run');
        $now    = gmdate('Y-m-d H:i:s');

        if ($dryRun) {
            // Just enumerate due jobs without firing.
            $db = \FreePBX::Database();
            $stmt = $db->prepare(
                "SELECT id, name, next_fire_utc FROM conferenceschedules_jobs
                 WHERE enabled = 1 AND next_fire_utc IS NOT NULL
                   AND next_fire_utc <= UTC_TIMESTAMP()"
            );
            $stmt->execute();
            $due = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $output->writeln(sprintf('<info>[dry-run] %s — %d job(s) due</info>', $now, count($due)));
            foreach ($due as $row) {
                $output->writeln(sprintf(
                    '  • job=%d name=%s next_fire_utc=%s',
                    $row['id'],
                    $row['name'],
                    $row['next_fire_utc']
                ));
            }
            return 0;
        }

        $report = $bmo->processTick();
        $output->writeln(sprintf(
            '<info>%s — %d/%d jobs fired</info>',
            $now,
            $report['fired'],
            $report['count']
        ));
        foreach ($report['errors'] as $err) {
            $output->writeln(sprintf('<error>job %d: %s</error>', $err['job_id'], $err['error']));
        }

        if (function_exists('dbug')) {
            dbug(sprintf(
                'conferenceschedules:tick %sZ fired=%d total=%d errors=%d',
                $now,
                $report['fired'],
                $report['count'],
                count($report['errors'])
            ));
        }

        return 0;
    }
}
