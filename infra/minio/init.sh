#!/bin/sh
# Wave MinIO bootstrap (idempotent, re-run on every `make up`).
#  - buckets wave-private / wave-public
#  - ADR 001 §5: anonymous GetObject on wave-private ONLY from the compose
#    subnet (nginx X-Accel), and only under audio/ (quarantine is never served)
#  - wave-public: anonymous GetObject (served read-only by nginx /media/)
#  - application user (S3_ACCESS_KEY) limited to the two buckets; the root
#    credentials stay with this init job.
set -eu

: "${MINIO_ROOT_USER:?}" "${MINIO_ROOT_PASSWORD:?}" "${S3_ACCESS_KEY:?}" "${S3_SECRET_KEY:?}"
: "${S3_BUCKET_PRIVATE:?}" "${S3_BUCKET_PUBLIC:?}" "${WAVE_INTERNAL_SUBNET:?}"

export MC_CONFIG_DIR=/tmp/.mc
work=$(mktemp -d)

until mc alias set wave http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" >/dev/null 2>&1; do
    echo "minio-init: waiting for minio"; sleep 2
done

mc mb --ignore-existing "wave/$S3_BUCKET_PRIVATE" "wave/$S3_BUCKET_PUBLIC"

cat > "$work/private.json" <<JSON
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "NginxInternalAudioRead",
    "Effect": "Allow",
    "Principal": {"AWS": ["*"]},
    "Action": ["s3:GetObject"],
    "Resource": ["arn:aws:s3:::${S3_BUCKET_PRIVATE}/audio/*"],
    "Condition": {"IpAddress": {"aws:SourceIp": ["${WAVE_INTERNAL_SUBNET}"]}}
  }]
}
JSON

cat > "$work/public.json" <<JSON
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "PublicMediaRead",
    "Effect": "Allow",
    "Principal": {"AWS": ["*"]},
    "Action": ["s3:GetObject"],
    "Resource": ["arn:aws:s3:::${S3_BUCKET_PUBLIC}/*"]
  }]
}
JSON

cat > "$work/app.json" <<JSON
{
  "Version": "2012-10-17",
  "Statement": [
    {"Effect": "Allow", "Action": ["s3:ListBucket", "s3:GetBucketLocation"],
     "Resource": ["arn:aws:s3:::${S3_BUCKET_PRIVATE}", "arn:aws:s3:::${S3_BUCKET_PUBLIC}"]},
    {"Effect": "Allow", "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
     "Resource": ["arn:aws:s3:::${S3_BUCKET_PRIVATE}/*", "arn:aws:s3:::${S3_BUCKET_PUBLIC}/*"]}
  ]
}
JSON

mc anonymous set-json "$work/private.json" "wave/$S3_BUCKET_PRIVATE"
mc anonymous set-json "$work/public.json" "wave/$S3_BUCKET_PUBLIC"

mc admin policy create wave wave-app "$work/app.json"
mc admin user add wave "$S3_ACCESS_KEY" "$S3_SECRET_KEY"
mc admin policy attach wave wave-app --user "$S3_ACCESS_KEY" 2>/dev/null || true

rm -rf "$work"
echo "minio-init: buckets and policies ready"
