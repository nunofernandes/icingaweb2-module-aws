<?php

namespace Icinga\Module\Aws;

use Aws\Api\DateTimeResult;
use Aws\Sdk;
use Icinga\Application\Config;

class AwsClient
{
    protected $key;

    protected $region;

    /**
     * @var Sdk
     */
    protected $sdk;

    public function __construct(AwsKey $key, $region)
    {
        $this->region = $region;
        $this->key = $key;
        $this->prepareAwsLibs();
    }

    public function getAutoscalingConfig()
    {
        $objects = array();
        $client = $this->sdk()->createAutoScaling();
        $res = $client->describeAutoScalingGroups();

        foreach ($res->get('AutoScalingGroups') as $entry) {

            $objects[] = $object = $this->extractAttributes($entry, array(
                'name'              => 'AutoScalingGroupName',
                'zones'             => 'AvailabilityZones',
                'lb_names'          => 'LoadBalancerNames',
                'health_check_type' => 'HealthCheckType',
            ), array(
                'launch_config'     => 'LaunchConfigurationName',
            ));

            $object->ctime        = strtotime($entry['CreatedTime']);
            $object->desired_size = (int) $entry['DesiredCapacity'];
            $object->min_size     = (int) $entry['MinSize'];
            $object->max_size     = (int) $entry['MaxSize'];
            $this->extractTags($entry, $object);
        }

        return $this->sortByName($objects);
    }

    public function getLoadBalancers()
    {
        $client = $this->sdk()->createElasticLoadBalancing();
        $res = $client->describeLoadBalancers();
        $objects = array();
        foreach ($res->get('LoadBalancerDescriptions') as $entry) {
            $objects[] = $object = $this->extractAttributes($entry, array(
                'name'    => 'LoadBalancerName',
                'dnsname' => 'DNSName',
                'scheme'  => 'Scheme',
                'zones'   => 'AvailabilityZones',
            ));

            $object->health_check = $entry['HealthCheck']['Target'];

            $object->listeners = (object) array();
            foreach ($entry['ListenerDescriptions'] as $l) {
                $listener = $l['Listener'];
                $object->listeners->{$listener['LoadBalancerPort']} = $this->extractAttributes(
                    $listener,
                    array(
                        'port'              => 'LoadBalancerPort',
                        'protocol'          => 'Protocol',
                        'instance_port'     => 'InstancePort',
                        'instance_protocol' => 'InstanceProtocol',
                    )
                );
            }
        }

        return $this->sortByName($objects);
    }

    public function getLoadBalancersV2()
    {
        $client = $this->sdk()->createElasticLoadBalancingV2();
        $res = $client->describeLoadBalancers();
        $objects = array();

        foreach ($res['LoadBalancers'] as $entry) {
            $objects[] = $object = $this->extractAttributes($entry, array(
                'name'    => 'LoadBalancerName',
                'dnsname' => 'DNSName',
                'scheme'  => 'Scheme',
                'zones'   => 'AvailabilityZones',
                'type'    => 'Type',
                'scheme'  => 'Scheme'
            ), array(
                'security_groups' => 'SecurityGroups'
            ));

            $object->state = $entry['State']['Code'];
        }

        return $this->sortByName($objects);
    }

    public function getEc2Instances()
    {
        $client = $this->sdk()->createEc2();
        $res = $client->describeInstances();
        $objects = array();
        foreach ($res->get('Reservations') as $reservation) {

            foreach ($reservation['Instances'] as $entry) {
                $objects[] = $object = $this->extractAttributes($entry, array(
                    'name'             => 'InstanceId',
                    'image'            => 'ImageId',
                    'architecture'     => 'Architecture',
                    'hypervisor'       => 'Hypervisor',
                    'virt_type'        => 'VirtualizationType',
                ), array(
                    'vpc_id'           => 'VpcId',
                    'root_device_type' => 'RootDeviceType',
                    'root_device_name' => 'RootDeviceName',
                    'public_ip'        => 'PublicIpAddress',
                    'public_dns'       => 'PublicDnsName',
                    'private_ip'       => 'PrivateIpAddress',
                    'private_dns'      => 'PrivateDnsName',
                    'instance_type'    => 'InstanceType',
                    'subnet_id'        => 'SubnetId'
                ));

                $object->disabled         = $entry['State']['Name'] != 'running';
                $object->monitoring_state = $entry['Monitoring']['State'];
                $object->status           = $entry['State']['Name'];
                $object->launch_time      = (string)$entry['LaunchTime'];
                $object->security_groups  = [];

                foreach ($entry['SecurityGroups'] as $group)
                {
                    $object->security_groups[] = $group['GroupName'];
                }

                $this->extractTags($entry, $object);
            }
        }

        return $this->sortByName($objects);
    }

    public function getRdsInstances()
    {
        $client = $this->sdk()->createRds();
        $res = $client->describeDBInstances();
        $objects = array();
        foreach ($res['DBInstances'] as $entry) {
            $objects[] = $object = $this->extractAttributes($entry, array(
                'name'    => 'DBInstanceIdentifier',
                'engine'  => 'Engine',
                'version' => 'EngineVersion',
            ));

            $object->port = $entry['Endpoint']['Port'];
            $object->fqdn = $entry['Endpoint']['Address'];
            $object->security_groups  = [];

            foreach ($entry['VpcSecurityGroups'] as $group)
            {
                $object->security_groups[] = $group['VpcSecurityGroupId'];
            }

            $this->extractTags($entry, $object);
        }

        return $this->sortByName($objects);
    }

    public static function enumRegions()
    {
        return array(
            'us-east-1'      => 'US East (N. Virginia)',
            'us-east-2'      => 'US East (Ohio)',
            'us-west-1'      => 'US West (N. California)',
            'us-west-2'      => 'US West (Oregon)',
            'ap-south-1'     => 'Asia Pacific (Mumbai)',
            'ap-northeast-2' => 'Asia Pacific (Seoul)',
            'ap-southeast-1' => 'Asia Pacific (Singapore)',
            'ap-southeast-2' => 'Asia Pacific (Sydney)',
            'ap-northeast-1' => 'Asia Pacific (Tokyo)',
            'ca-central-1'   => 'Canada (Central)',
            'eu-central-1'   => 'EU (Frankfurt)',
            'eu-west-1'      => 'EU (Ireland)',
            'eu-west-2'      => 'EU (London)',
            'eu-west-3'      => 'EU (Paris)',
            'sa-east-1'      => 'South America (São Paulo)',
        );
    }

    protected function sortByName($objects)
    {
        usort($objects, array($this, 'compareName'));
        return $objects;
    }

    protected function extractAttributes($entry, $required, $optional = array(), $subkey = null)
    {
        $result = (object) array();
        if ($subkey !== null) {
            $entry = $entry[$subkey];
        }

        foreach ($required as $alias => $key) {
            $result->$alias = $entry[$key];
        }

        foreach ($optional as $alias => $key) {
            if (array_key_exists($key, $entry)) {
                $result->$alias = $entry[$key];
            } else {
                $result->$alias = null;
            }
        }

        return $result;
    }

    protected function extractTags($entry, $result)
    {
        $result->tags = (object) array();
        if (! array_key_exists('Tags', $entry)) {
            return;
        }

        foreach ($entry['Tags'] as $t) {
            $result->tags->{$t['Key']} = $t['Value'];
        }
    }

    protected function compareName($a, $b)
    {
        return strcmp($a->name, $b->name);
    }

    /**
     * @return Sdk
     */
    protected function sdk()
    {
        if ($this->sdk === null) {
            $this->initializeSdk();
        }

        return $this->sdk;
    }

    protected function initializeSdk()
    {
        $params = array(
            'version' => 'latest',
            'region'  => $this->region,
        );

        if ($this->key instanceof AwsKey) {
            $params['credentials'] = $this->key->getCredentials();
        }

        $config = Config::module('aws');
        if ($proxy = $config->get('network', 'proxy')) {
            $params['request.options'] = array(
                'proxy' => $proxy
            );
        }

        if ($ca = $config->get('network', 'ssl_ca')) {
            $params['ssl.certificate_authority'] = $ca;
        }

        $this->sdk = new Sdk($params);
    }

    protected function prepareAwsLibs()
    {
        if (class_exists('\Aws\Sdk')) {
            return;
        }

        $autoloaderFiles = array(
            dirname(__DIR__) . '/vendor/aws/aws-autoloader.php', // manual sdk installation
            dirname(__DIR__) . '/vendor/autoload.php',           // composer installation
        );

        foreach ($autoloaderFiles as $file) {
            if (file_exists($file)) {
                require_once $file;
            }
        }

        if (! class_exists('\Aws\Sdk')) {
            throw new \RuntimeException('AWS SDK not found (Class \Aws\Sdk not found)');
        }
    }
}
