<?php
// File storage for capsule attachments.
//
// This is the only file that reads or writes uploaded files. They are kept in Amazon S3,
// in the bucket S3_BUCKET under the "uploads/" prefix. The rest of the app only uses the
// three functions storage_save(), storage_delete() and storage_stream().
//
// There are no access keys in .env: on EC2 the SDK takes temporary credentials of the
// server role (timecapsule-dev-app-role) from the instance metadata.

use Aws\S3\S3Client;

const STORAGE_PREFIX = 'uploads/';
const STORAGE_LINK_LIFETIME = '+5 minutes';

function s3_client(): S3Client
{
    static $client = null;
    if ($client === null) {
        $client = new S3Client(['region' => config('AWS_REGION', 'eu-north-1')]);
    }
    return $client;
}

function s3_bucket(): string
{
    $bucket = config('S3_BUCKET');
    if ($bucket === null) {
        throw new RuntimeException('S3_BUCKET is not set in .env.');
    }
    return $bucket;
}

// Save an uploaded file ($_FILES['...'] entry) and return its stored name, e.g. "3f9a...e1.png".
// The name is random, so users cannot guess other people's file names.
function storage_save(array $uploadedFile, string $extension): string
{
    $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

    s3_client()->putObject([
        'Bucket' => s3_bucket(),
        'Key' => STORAGE_PREFIX . $storedName,
        'SourceFile' => $uploadedFile['tmp_name'],
        'ContentType' => mime_content_type($uploadedFile['tmp_name']) ?: 'application/octet-stream',
    ]);
    return $storedName;
}

// With versioning on, this only adds a delete marker: the file can still be restored,
// and the lifecycle rule removes the old version later.
function storage_delete(?string $storedName): void
{
    if ($storedName === null || $storedName === '') {
        return;
    }

    s3_client()->deleteObject([
        'Bucket' => s3_bucket(),
        'Key' => STORAGE_PREFIX . basename($storedName),
    ]);
}

// Send the browser to a presigned S3 URL instead of streaming the file through PHP.
// $disposition: "inline" (show in the browser) or "attachment" (download).
// The caller has already checked that the current user may see this capsule.
function storage_stream(string $storedName, string $mime, string $downloadName, string $disposition): void
{
    // Keep only safe characters for the plain filename; the UTF-8 name goes in filename*.
    $asciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName);

    $client = s3_client();
    $command = $client->getCommand('GetObject', [
        'Bucket' => s3_bucket(),
        'Key' => STORAGE_PREFIX . basename($storedName),
        'ResponseContentType' => $mime,
        'ResponseContentDisposition' => sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $disposition,
            $asciiName,
            rawurlencode($downloadName)
        ),
    ]);
    $url = (string) $client->createPresignedRequest($command, STORAGE_LINK_LIFETIME)->getUri();

    header('Cache-Control: no-store');
    header('Location: ' . $url, true, 302);
    exit;
}
