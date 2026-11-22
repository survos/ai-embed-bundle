<?php

declare(strict_types=1);

namespace Survos\AiEmbedBundle;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

final class S3EmbeddingStorage
{
    public static function create(array $config): FilesystemOperator
    {
        if (!class_exists(AwsS3V3Adapter::class)) {
            throw new \LogicException('S3 snapshots require composer require league/flysystem-aws-s3-v3. Alternatively configure remote_storage with an existing Flysystem service.');
        }
        if ('' === trim($config['bucket'] ?? '')) {
            throw new \LogicException('Configure survos_ai_embed.s3.bucket or remote_storage before using embed:push/pull/merge.');
        }
        $options = ['version' => '2006-03-01', 'region' => $config['region'] ?? 'us-east-1',
            'use_path_style_endpoint' => $config['path_style'] ?? true];
        if ($config['endpoint'] ?? '') {
            $options['endpoint'] = $config['endpoint'];
        }
        $key = $config['access_key'] ?? '';
        $secret = $config['secret_key'] ?? '';
        if (('' === $key) !== ('' === $secret)) {
            throw new \LogicException('S3 access and secret keys must be supplied together.');
        }
        if ('' !== $key) {
            $options['credentials'] = ['key' => $key, 'secret' => $secret];
            if ($config['session_token'] ?? '') {
                $options['credentials']['token'] = $config['session_token'];
            }
        }

        return new Filesystem(new AwsS3V3Adapter(new S3Client($options), $config['bucket'], $config['prefix'] ?? ''));
    }
}
