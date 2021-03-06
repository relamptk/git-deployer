<?php
namespace GitDeployer\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\Table;

class StatusCommand extends Command {
    
    protected function configure() {

        $this
            ->setName('status')
            ->setDescription('Display the current deployment status of your Git repositories');

    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        
        // -> Get logged-in service       
        $instance = \GitDeployer\AppInstance::getInstance();
        $appService = $instance->service();
        $appService->setInstances($input, $output, $this->getHelperSet());

        // .. and storage
        $storage = $instance->storage();
        if ($storage == null) throw new \RuntimeException('Please configure Git-Deployer first!');

        $projects = $appService->getProjects();

        // -> Print out status table
        $table = new Table($output);
        $table->setHeaders(array(
            'ID',
            'Name',
            'Version',
            'Status'
        ));

        $deploymentStatuses = array();

        foreach ($projects as $key => $project) {
            $status = $storage->getDeploymentStatus($project);

            $deploymentStatuses[] = array(
                $project->id(),
                ( $project->namespace() ? $project->namespace() . ' / ' : '' ) . $project->name(),
                $status->added() ? $status->getDeployedVersion() : 'N/A',
                $status->added() ? $status->getDeploymentInfo() : 'Not added to Git-Deployer yet',
            );             
        }

        // Now sort them via "spaceship"
        usort($deploymentStatuses, function ($item1, $item2) {
                return $item1[1] <=> $item2[1];
        });

        $table->setRows($deploymentStatuses);   
        $table->render();

    }

}
