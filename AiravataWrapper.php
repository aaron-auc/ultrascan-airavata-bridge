<?php

namespace SCIGAP;

$filepath = realpath(dirname(__FILE__));
$GLOBALS['THRIFT_ROOT'] = $filepath . '/lib/Thrift/';
$GLOBALS['AIRAVATA_ROOT'] = $filepath . '/lib/Airavata/';

require_once $GLOBALS['THRIFT_ROOT'] . 'Transport/TTransport.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Transport/TSocket.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Protocol/TProtocol.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Protocol/TBinaryProtocol.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TApplicationException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TProtocolException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Exception/TTransportException.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Base/TBase.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Type/TType.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Type/TMessageType.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Factory/TStringFuncFactory.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'StringFunc/TStringFunc.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'StringFunc/Core.php';
require_once $GLOBALS['THRIFT_ROOT'] . 'Type/TConstant.php';

require_once $GLOBALS['AIRAVATA_ROOT'] . 'API/Airavata.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'API/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'API/Error/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Security/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Workspace/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Experiment/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Scheduling/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Status/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Commons/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/AppCatalog/AppInterface/Types.php';
require_once $GLOBALS['AIRAVATA_ROOT'] . 'Model/Application/Io/Types.php';

require_once "AiravataWrapperInterface.php";
require_once "AiravataUtils.php";

use Thrift\Transport\TSocket;
use Thrift\Protocol\TBinaryProtocol;
use Thrift\Exception\TException;
use Airavata\API\AiravataClient;
use Airavata\Model\Security\AuthzToken;
use Airavata\Model\Status\ExperimentState;
use Airavata\Model\Status\JobState;
use Airavata\API\Error\InvalidRequestException;
use Airavata\API\Error\AiravataClientException;
use Airavata\API\Error\AiravataSystemException;
use Airavata\API\Error\ExperimentNotFoundException;

class AiravataWrapper implements AiravataWrapperInterface
{
    private $airavataclient;
    private $transport;
    private $authToken;
    private $airavataconfig;
    private $gatewayId;

    function __construct()
    {
        $this->airavataconfig = parse_ini_file("airavata-client-properties.ini");

        $this->transport = new TSocket($this->airavataconfig['AIRAVATA_SERVER'], $this->airavataconfig['AIRAVATA_PORT']);
        $this->transport->setRecvTimeout($this->airavataconfig['AIRAVATA_TIMEOUT']);
        $this->transport->setSendTimeout($this->airavataconfig['AIRAVATA_TIMEOUT']);

        $protocol = new TBinaryProtocol($this->transport);
        $this->transport->open();
        $this->airavataclient = new AiravataClient($protocol);

        $this->authToken = new AuthzToken();
        $this->authToken->accessToken = "";

        $this->gatewayId = $this->airavataconfig['GATEWAY_ID'];
    }

    function __destruct()
    {
        /** Closes Connection to Airavata Server */
        $this->transport->close();
    }

    /**
     * This function calls Airavata Launch Experiments. Inside the implementation, all the required steps such as
     *  creating an experiment and then launching is taken care of.
     *
     * @param string $limsHost - Host where LIMS is deployed.
     * @param string $limsUser - Unique user name of LIMS User
     * @param string $experimentName - Name of the Experiment - US3-AIRA, US3-ADEV ..
     * @param string $requestId - LIMS Instance concatenated with incremented request ID. Ex: uslims3_CU_Boulder_1974
     * @param string $computeCluster - Host Name of the Compute Cluster. Ex: comet.sdsc.edu
     * @param string $queue - Queue Name on the cluster
     * @param integer $cores - Number of Cores to be requested.
     * @param integer $nodes - Number of Nodes to be requested.
     * @param integer $mGroupCount - Parallel groups.
     * @param integer $wallTime - Maximum wall time of the job.
     * @param string $clusterUserName - Jureca submissions will use this value to construct the userDN. Other clusters ignore it.
     * @param string $inputFile - Path of the Input Tar File
     * @param string $outputDataDirectory - Directory path where Airavata should stage back the output tar file.
     *
     * @return array - The array will have three values: $launchStatus, $experimentId, $message
     *
     */
    function launch_airavata_experiment($limsHost, $limsUser, $experimentName, $requestId,
                                        $computeCluster, $queue, $cores, $nodes, $mGroupCount, $wallTime, $clusterUserName,
                                        $inputFile, $outputDataDirectory)
    {
        /** Test Airavata API Connection */
//        $version = $this->airavataclient->getAPIVersion($this->authToken);
//        echo $version .PHP_EOL;

        $projectId = fetch_projectid($this->airavataclient, $this->authToken, $this->gatewayId, $limsUser);

        $experimentModel = create_experiment_model($this->airavataclient, $this->authToken, $this->airavataconfig, $this->gatewayId, $projectId, $limsHost, $limsUser, $experimentName, $requestId,
            $computeCluster, $queue, $cores, $nodes, $mGroupCount, $wallTime, $clusterUserName,
            $inputFile, $outputDataDirectory);

        $experimentId = $this->airavataclient->createExperiment($this->authToken, $this->gatewayId, $experimentModel);

        $this->airavataclient->launchExperiment($this->authToken, $experimentId, $this->gatewayId);

        $returnArray = array(
            "launchStatus" => true,
            "experimentId" => $experimentId,
            "message" => "Experiment Created and Launched as Expected. No errors"
        );

        return $returnArray;
    }

    /**
     * This function calls fetches Airavata Experiment Status.
     *
     * @param string $experimentId - Id of the Experiment.
     *
     * @return string - Status of the experiment.
     *
     */
    function get_experiment_status($experimentId)
    {

        $experimentStatus = $this->airavataclient->getExperimentStatus($this->authToken, $experimentId);
        $experimentState = ExperimentState::$__names[$experimentStatus->state];

        switch ($experimentState)
        {
            case 'EXECUTING':
                $jobStatuses = $this->airavataclient->getJobStatuses($this->authToken, $experimentId);
                $jobNames = array_keys($jobStatuses);
                $jobState = JobState::$__names[$jobStatuses[$jobNames[0]]->jobState];
                if ( $jobState == 'QUEUED'  ||  $jobState == 'ACTIVE' )
                    $experimentState  = $jobState;
                break;
            case 'COMPLETED':
                $jobStatuses = $this->airavataclient->getJobStatuses($this->authToken, $experimentId);
                $jobNames = array_keys($jobStatuses);
                $jobState = JobState::$__names[$jobStatuses[$jobNames[0]]->jobState];
                if ( $jobState == 'COMPLETED'  ||  $jobState == 'FAILED' )
                    $experimentState    = $jobState;
                break;
            case '':
            case 'UNKNOWN':
                break;
            default:
                break;
        }
        return $experimentState;
    }

    /**
     * This function calls fetches errors from an Airavata Experiment.
     *
     * @param string $experimentId - Id of the Experiment.
     *
     * @return array - The array will have any errors if recorded.
     *
     */
    function get_experiment_errors($experimentId)
    {
        $experimentModel = $this->airavataclient->getExperiment($this->authToken, $experimentId);
        $experimentErrors = $experimentModel->errors;
        if ($experimentErrors != null) {
            foreach ($experimentErrors as $experimentError) {
                $actualError = $experimentError->actualErrorMessage;
                return $actualError;
            }
        }
    }

    /**
     * This function calls terminates previously launched Airavata Experiment.
     *
     * @param string $experimentId - Id of the Experiment to be terminated.
     *
     * @return array - The array will have two values: $cancelStatus, $message
     *
     */
    function terminate_airavata_experiment($experimentId)
    {
        $this->airavataclient->terminateExperiment($this->authToken, $experimentId, $this->gatewayId);

        $returnArray = array(
            "terminateStatus" => true,
            "message" => "Experiment Created and Launched as Expected. No errors"
        );

        return $returnArray;
    }

}