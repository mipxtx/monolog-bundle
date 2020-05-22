<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MonologBundle\Tests\DependencyInjection;

use InvalidArgumentException;
use Monolog\Logger;
use Symfony\Bundle\MonologBundle\DependencyInjection\MonologExtension;
use Symfony\Bundle\MonologBundle\DependencyInjection\Compiler\LoggerChannelPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

class MonologExtensionTest extends DependencyInjectionTest
{
    public function testLoadWithDefault()
    {
        $container = $this->getContainer([['handlers' => ['main' => ['type' => 'stream']]]]);

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\StreamHandler');
        $this->assertDICConstructorArguments($handler, ['%kernel.logs_dir%/%kernel.environment%.log', \Monolog\Logger::DEBUG, true, null, false]);
        $this->assertDICDefinitionMethodCallAt(0, $handler, 'pushProcessor', [new Reference('monolog.processor.psr_log_message')]);
    }

    public function testLoadWithCustomValues()
    {
        $container = $this->getContainer([['handlers' => [
            'custom' => ['type' => 'stream', 'path' => '/tmp/symfony.log', 'bubble' => false, 'level' => 'ERROR', 'file_permission' => '0666', 'use_locking' => true]
        ]]]);
        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.custom'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.custom')]);

        $handler = $container->getDefinition('monolog.handler.custom');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\StreamHandler');
        $this->assertDICConstructorArguments($handler, ['/tmp/symfony.log', \Monolog\Logger::ERROR, false, 0666, true]);
    }

    public function testLoadWithNestedHandler()
    {
        $container = $this->getContainer([['handlers' => [
            'custom' => ['type' => 'stream', 'path' => '/tmp/symfony.log', 'bubble' => false, 'level' => 'ERROR', 'file_permission' => '0666'],
            'nested' => ['type' => 'stream', 'path' => '/tmp/symfony.log', 'bubble' => false, 'level' => 'ERROR', 'file_permission' => '0666', 'nested' => true]
        ]]]);
        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.custom'));
        $this->assertTrue($container->hasDefinition('monolog.handler.nested'));

        $logger = $container->getDefinition('monolog.logger');
        // Nested handler must not be pushed to logger
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.custom')]);

        $handler = $container->getDefinition('monolog.handler.custom');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\StreamHandler');
        $this->assertDICConstructorArguments($handler, ['/tmp/symfony.log', \Monolog\Logger::ERROR, false, 0666, false]);
    }

    public function testLoadWithServiceHandler()
    {
        $container = $this->getContainer(
            [['handlers' => ['custom' => ['type' => 'service', 'id' => 'some.service.id']]]],
            ['some.service.id' => new Definition('stdClass', ['foo', false])]
        );

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasAlias('monolog.handler.custom'));

        $logger = $container->getDefinition('monolog.logger');
        // Custom service handler must be pushed to logger
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.custom')]);

        $handler = $container->findDefinition('monolog.handler.custom');
        $this->assertDICDefinitionClass($handler, 'stdClass');
        $this->assertDICConstructorArguments($handler, ['foo', false]);
    }

    public function testLoadWithNestedServiceHandler()
    {
        $container = $this->getContainer(
            [['handlers' => ['custom' => ['type' => 'service', 'id' => 'some.service.id', 'nested' => true]]]],
            ['some.service.id' => new Definition('stdClass', ['foo', false])]
        );

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasAlias('monolog.handler.custom'));

        $logger = $container->getDefinition('monolog.logger');
        // Nested service handler must not be pushed to logger
        $this->assertCount(1, $logger->getMethodCalls());
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);

        $handler = $container->findDefinition('monolog.handler.custom');
        $this->assertDICDefinitionClass($handler, 'stdClass');
        $this->assertDICConstructorArguments($handler, ['foo', false]);
    }

    /**
     * @expectedException InvalidArgumentException
     */
    public function testExceptionWhenInvalidHandler()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['main' => ['type' => 'invalid_handler']]]], $container);
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testExceptionWhenUsingFingerscrossedWithoutHandler()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['main' => ['type' => 'fingers_crossed']]]], $container);
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testExceptionWhenUsingFilterWithoutHandler()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['main' => ['type' => 'filter']]]], $container);
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testExceptionWhenUsingBufferWithoutHandler()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['main' => ['type' => 'buffer']]]], $container);
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testExceptionWhenUsingGelfWithoutPublisher()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['gelf' => ['type' => 'gelf']]]], $container);
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testExceptionWhenUsingGelfWithoutPublisherHostname()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['gelf' => ['type' => 'gelf', 'publisher' => []]]]], $container);
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testExceptionWhenUsingServiceWithoutId()
    {
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['main' => ['type' => 'service']]]], $container);
    }

    /**
     * @expectedException Symfony\Component\Config\Definition\Exception\InvalidConfigurationException
     */
    public function testExceptionWhenUsingDebugName()
    {
        // logger
        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['debug' => ['type' => 'stream']]]], $container);
    }

    public function testSyslogHandlerWithLogopts()
    {
        $container = $this->getContainer([['handlers' => ['main' => ['type' => 'syslog', 'logopts' => LOG_CONS]]]]);

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\SyslogHandler');
        $this->assertDICConstructorArguments($handler, [false, 'user', \Monolog\Logger::DEBUG, true, LOG_CONS]);
    }

    public function testRollbarHandlerCreatesNotifier()
    {
        $container = $this->getContainer([['handlers' => ['main' => ['type' => 'rollbar', 'token' => 'MY_TOKEN']]]]);

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\RollbarHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('monolog.rollbar.notifier.1c8e6a67728dff6a209f828427128dd8b3c2b746'), \Monolog\Logger::DEBUG, true]);
    }

    public function testRollbarHandlerReusesNotifier()
    {
        $container = $this->getContainer(
            [['handlers' => ['main' => ['type' => 'rollbar', 'id' => 'my_rollbar_id']]]],
            ['my_rollbar_id' => new Definition('my_rollbar_id')]
        );

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\RollbarHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('my_rollbar_id'), \Monolog\Logger::DEBUG, true]);
    }

    public function testSocketHandler()
    {
        try {
            $this->getContainer([['handlers' => ['socket' => ['type' => 'socket']]]]);
            $this->fail();
        } catch (InvalidConfigurationException $e) {
            $this->assertContains('connection_string', $e->getMessage());
        }

        $container = $this->getContainer([['handlers' => ['socket' => [
            'type' => 'socket', 'timeout' => 1, 'persistent' => true,
            'connection_string' => 'localhost:50505', 'connection_timeout' => '0.6'
        ]]]]);
        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.socket'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.socket')]);

        $handler = $container->getDefinition('monolog.handler.socket');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\SocketHandler');
        $this->assertDICConstructorArguments($handler, ['localhost:50505', \Monolog\Logger::DEBUG, true]);
        $this->assertDICDefinitionMethodCallAt(0, $handler, 'pushProcessor', [new Reference('monolog.processor.psr_log_message')]);
        $this->assertDICDefinitionMethodCallAt(1, $handler, 'setTimeout', ['1']);
        $this->assertDICDefinitionMethodCallAt(2, $handler, 'setConnectionTimeout', ['0.6']);
        $this->assertDICDefinitionMethodCallAt(3, $handler, 'setPersistent', [true]);
    }

    public function testRavenHandlerWhenConfigurationIsWrong()
    {
        try {
            $this->getContainer([['handlers' => ['raven' => ['type' => 'raven']]]]);
            $this->fail();
        } catch (InvalidConfigurationException $e) {
            $this->assertContains('DSN', $e->getMessage());
        }
    }

    public function testRavenHandlerWhenADSNIsSpecified()
    {
        if (Logger::API === 2) {
            $this->markTestSkipped('"raven" was removed in Monolog v2');
        }
        $dsn = 'http://43f6017361224d098402974103bfc53d:a6a0538fc2934ba2bed32e08741b2cd3@marca.python.live.cheggnet.com:9000/1';

        $container = $this->getContainer([['handlers' => ['raven' => [
            'type' => 'raven', 'dsn' => $dsn
        ]]]]);
        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.raven'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.raven')]);

        $this->assertTrue($container->hasDefinition('monolog.raven.client.'.sha1($dsn)));

        $handler = $container->getDefinition('monolog.handler.raven');

        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\RavenHandler');
    }

    public function testRavenHandlerWhenADSNAndAClientAreSpecified()
    {
        if (Logger::API === 2) {
            $this->markTestSkipped('"raven" was removed in Monolog v2');
        }

        $container = $this->getContainer([['handlers' => ['raven' => [
            'type' => 'raven', 'dsn' => 'foobar', 'client_id' => 'raven.client'
        ]]]],
            ['raven.client' => new Definition('stdClass', ['foo', false])]
        );

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.raven')]);

        $handler = $container->getDefinition('monolog.handler.raven');
        $this->assertDICConstructorArguments($handler, [new Reference('raven.client'), 100, true]);
    }

    public function testRavenHandlerWhenAClientIsSpecified()
    {
        if (Logger::API === 2) {
            $this->markTestSkipped('"raven" was removed in Monolog v2');
        }

        $container = $this->getContainer([['handlers' => ['raven' => [
            'type' => 'raven', 'client_id' => 'raven.client'
        ]]]],
            ['raven.client' => new Definition('stdClass', ['foo', false])]
        );

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.raven')]);

        $handler = $container->getDefinition('monolog.handler.raven');
        $this->assertDICConstructorArguments($handler, [new Reference('raven.client'), 100, true]);
    }

    public function testSentryHandlerWhenConfigurationIsWrong()
    {
        try {
            $this->getContainer([['handlers' => ['sentry' => ['type' => 'sentry']]]]);
            $this->fail();
        } catch (InvalidConfigurationException $e) {
            $this->assertContains('DSN', $e->getMessage());
        }
    }

    public function testSentryHandlerWhenADSNIsSpecified()
    {
        $dsn = 'http://43f6017361224d098402974103bfc53d:a6a0538fc2934ba2bed32e08741b2cd3@marca.python.live.cheggnet.com:9000/1';

        $container = $this->getContainer([['handlers' => ['sentry' => [
            'type' => 'sentry', 'dsn' => $dsn
        ]]]]);
        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.sentry'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.sentry')]);

        $handler = $container->getDefinition('monolog.handler.sentry');
        $this->assertDICDefinitionClass($handler, 'Sentry\Monolog\Handler');
    }

    public function testSentryHandlerWhenADSNAndAClientAreSpecified()
    {
        $container = $this->getContainer(
            [
                [
                    'handlers' => [
                        'sentry' => [
                            'type' => 'sentry',
                            'dsn' => 'foobar',
                            'client_id' => 'sentry.client',
                        ],
                    ],
                ],
            ],
            [
                'sentry.client' => new Definition('Sentry\Client'),
            ]
        );

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.sentry')]);

        $handler = $container->getDefinition('monolog.handler.sentry');
        $this->assertDICConstructorArguments($handler->getArguments()[0], [new Reference('sentry.client')]);
    }

    public function testSentryHandlerWhenAClientIsSpecified()
    {
        $container = $this->getContainer(
            [
                [
                    'handlers' => [
                        'sentry' => [
                            'type' =>
                            'sentry',
                            'client_id' => 'sentry.client',
                        ],
                    ],
                ],
            ],
            [
                'sentry.client' => new Definition('Sentry\Client'),
            ]
        );

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.sentry')]);

        $handler = $container->getDefinition('monolog.handler.sentry');
        $this->assertDICConstructorArguments($handler->getArguments()[0], [new Reference('sentry.client')]);
    }

    public function testLogglyHandler()
    {
        $token = '026308d8-2b63-4225-8fe9-e01294b6e472';
        try {
            $this->getContainer([['handlers' => ['loggly' => ['type' => 'loggly']]]]);
            $this->fail();
        } catch (InvalidConfigurationException $e) {
            $this->assertContains('token', $e->getMessage());
        }

        try {
            $this->getContainer([['handlers' => ['loggly' => [
                'type' => 'loggly', 'token' => $token, 'tags' => 'x, 1zone ,www.loggly.com,-us,apache$'
            ]]]]);
            $this->fail();
        } catch (InvalidConfigurationException $e) {
            $this->assertContains('-us, apache$', $e->getMessage());
        }

        $container = $this->getContainer([['handlers' => ['loggly' => [
            'type' => 'loggly', 'token' => $token
        ]]]]);
        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.loggly'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.loggly')]);
        $handler = $container->getDefinition('monolog.handler.loggly');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\LogglyHandler');
        $this->assertDICConstructorArguments($handler, [$token, \Monolog\Logger::DEBUG, true]);
        $this->assertDICDefinitionMethodCallAt(0, $handler, 'pushProcessor', [new Reference('monolog.processor.psr_log_message')]);

        $container = $this->getContainer([['handlers' => ['loggly' => [
            'type' => 'loggly', 'token' => $token, 'tags' => [' ', 'foo', '', 'bar']
        ]]]]);
        $handler = $container->getDefinition('monolog.handler.loggly');
        $this->assertDICDefinitionMethodCallAt(0, $handler, 'pushProcessor', [new Reference('monolog.processor.psr_log_message')]);
        $this->assertDICDefinitionMethodCallAt(1, $handler, 'setTag', ['foo,bar']);
    }

    /** @group legacy */
    public function testFingersCrossedHandlerWhenExcluded404sAreSpecified()
    {
        $container = $this->getContainer(
            [['handlers' => [
                'main' => ['type' => 'fingers_crossed', 'handler' => 'nested', 'excluded_404s' => ['^/foo', '^/bar']],
                'nested' => ['type' => 'stream', 'path' => '/tmp/symfony.log']
            ]]],
            ['request_stack' => new Definition('request_stack')]
            );

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));
        $this->assertTrue($container->hasDefinition('monolog.handler.nested'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main.not_found_strategy'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);

        $strategy = $container->getDefinition('monolog.handler.main.not_found_strategy');
        $this->assertDICDefinitionClass($strategy, 'Symfony\Bridge\Monolog\Handler\FingersCrossed\NotFoundActivationStrategy');
        $this->assertDICConstructorArguments($strategy, [new Reference('request_stack'), ['^/foo', '^/bar'], \Monolog\Logger::WARNING]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\FingersCrossedHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('monolog.handler.nested'), new Reference('monolog.handler.main.not_found_strategy'), 0, true, true, null]);
    }

    public function testFingersCrossedHandlerWhenExcludedHttpCodesAreSpecified()
    {
        if (!class_exists('Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy')) {
            $this->markTestSkipped('Symfony Monolog 4.1+ is needed.');
        }

        $container = $this->getContainer([['handlers' => [
            'main' => [
                'type' => 'fingers_crossed',
                'handler' => 'nested',
                'excluded_http_codes' => [403, 404, [405 => ['^/foo', '^/bar']]]
            ],
            'nested' => ['type' => 'stream', 'path' => '/tmp/symfony.log']
        ]]], ['request_stack' => new Definition('request_stack')]);

        $this->assertTrue($container->hasDefinition('monolog.logger'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main'));
        $this->assertTrue($container->hasDefinition('monolog.handler.nested'));
        $this->assertTrue($container->hasDefinition('monolog.handler.main.http_code_strategy'));

        $logger = $container->getDefinition('monolog.logger');
        $this->assertDICDefinitionMethodCallAt(0, $logger, 'useMicrosecondTimestamps', ['%monolog.use_microseconds%']);
        $this->assertDICDefinitionMethodCallAt(1, $logger, 'pushHandler', [new Reference('monolog.handler.main')]);

        $strategy = $container->getDefinition('monolog.handler.main.http_code_strategy');
        $this->assertDICDefinitionClass($strategy, 'Symfony\Bridge\Monolog\Handler\FingersCrossed\HttpCodeActivationStrategy');
        $this->assertDICConstructorArguments($strategy, [
            new Reference('request_stack'),
            [
                ['code' => 403, 'urls' => []],
                ['code' => 404, 'urls' => []],
                ['code' => 405, 'urls' => ['^/foo', '^/bar']]
            ],
            \Monolog\Logger::WARNING
        ]);

        $handler = $container->getDefinition('monolog.handler.main');
        $this->assertDICDefinitionClass($handler, 'Monolog\Handler\FingersCrossedHandler');
        $this->assertDICConstructorArguments($handler, [new Reference('monolog.handler.nested'), new Reference('monolog.handler.main.http_code_strategy'), 0, true, true, null]);
    }

    /**
     * @param string $handlerType
     * @dataProvider v2RemovedDataProvider
     */
    public function testV2Removed($handlerParams)
    {
        if (Logger::API === 1) {
            $this->markTestSkipped('Not valid for V1');

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('"%s" was removed in Monolog v2.', $handlerParams['type']));

        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['main' => $handlerParams]]], $container);
    }

    public function v2RemovedDataProvider()
    {
        return [
            [['type' => 'hipchat', 'token' => 'token', 'room' => 'room']],
            [['type' => 'raven', 'dsn' => 'dsn', 'client_id' => 'client_id']],
            [['type' => 'slackbot', 'team' => 'team', 'token' => 'token', 'channel' => 'channel']],
        ];
    }

    /**
     * @param string $handlerType
     * @dataProvider v1AddedDataProvider
     */
    public function testV2AddedOnV1($handlerType)
    {
        if (Logger::API === 2) {
            $this->markTestSkipped('Not valid for V2');

            return;
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            sprintf('"%s" was added in Monolog v2, please upgrade if you wish to use it.', $handlerType)
        );

        $container = new ContainerBuilder();
        $loader = new MonologExtension();

        $loader->load([['handlers' => ['main' => ['type' => $handlerType]]]], $container);
    }

    public function v1AddedDataProvider()
    {
        return [
            ['fallbackgroup'],
        ];
    }


    protected function getContainer(array $config = [], array $thirdPartyDefinitions = [])
    {
        $container = new ContainerBuilder();
        foreach ($thirdPartyDefinitions as $id => $definition) {
            $container->setDefinition($id, $definition);
        }

        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->addCompilerPass(new LoggerChannelPass());

        $loader = new MonologExtension();
        $loader->load($config, $container);
        $container->compile();

        return $container;
    }
}
