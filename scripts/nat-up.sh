#!/bin/bash
# Временный NAT Gateway для установки пакетов на db-server.
set -euo pipefail

AWS_REGION=eu-north-1
PUBLIC_SUBNET_ID=subnet-0a8601f4738514edb
PRIVATE_RT_ID=rtb-0303fce39a59e68ff
OWNER=sta5on
STATE_FILE="$(dirname "$0")/.nat-state"

export AWS_REGION AWS_DEFAULT_REGION=$AWS_REGION

tags() {
  echo "ResourceType=$1,Tags=[{Key=Name,Value=$2},{Key=Project,Value=timecapsule},{Key=Environment,Value=dev},{Key=Owner,Value=$OWNER},{Key=Lab,Value=lab3}]"
}

echo "==> Выделяем Elastic IP"
ALLOC_ID=$(aws ec2 allocate-address --domain vpc \
  --tag-specifications "$(tags elastic-ip timecapsule-dev-nat-a-eip)" \
  --query AllocationId --output text)
echo "ALLOC_ID=$ALLOC_ID" > "$STATE_FILE"

echo "==> Создаём NAT Gateway в public-a"
NAT_ID=$(aws ec2 create-nat-gateway --subnet-id "$PUBLIC_SUBNET_ID" --allocation-id "$ALLOC_ID" \
  --tag-specifications "$(tags natgateway timecapsule-dev-nat-a)" \
  --query NatGateway.NatGatewayId --output text)
echo "NAT_ID=$NAT_ID" >> "$STATE_FILE"

echo "==> Ждём, пока шлюз станет доступен"
aws ec2 wait nat-gateway-available --nat-gateway-ids "$NAT_ID"

echo "==> Добавляем маршрут 0.0.0.0/0 в private-rt"
aws ec2 create-route --route-table-id "$PRIVATE_RT_ID" --destination-cidr-block 0.0.0.0/0 \
  --nat-gateway-id "$NAT_ID" > /dev/null

echo "Готово. NAT Gateway $NAT_ID, Elastic IP $ALLOC_ID"
