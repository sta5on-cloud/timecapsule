#!/bin/bash
# Удаляет временный NAT Gateway и освобождает его Elastic IP.
set -euo pipefail

AWS_REGION=eu-north-1
PRIVATE_RT_ID=rtb-0303fce39a59e68ff
STATE_FILE="$(dirname "$0")/.nat-state"

export AWS_REGION AWS_DEFAULT_REGION=$AWS_REGION

source "$STATE_FILE"

echo "==> Удаляем маршрут из private-rt"
if aws ec2 describe-route-tables --route-table-ids "$PRIVATE_RT_ID" \
  --query 'RouteTables[0].Routes[?DestinationCidrBlock==`0.0.0.0/0`]' --output text | grep -q .; then
  aws ec2 delete-route --route-table-id "$PRIVATE_RT_ID" --destination-cidr-block 0.0.0.0/0
fi

echo "==> Удаляем NAT Gateway"
STATE=$(aws ec2 describe-nat-gateways --nat-gateway-ids "$NAT_ID" --query 'NatGateways[0].State' --output text)
if [ "$STATE" != "deleted" ]; then
  aws ec2 delete-nat-gateway --nat-gateway-id "$NAT_ID" > /dev/null
fi
aws ec2 wait nat-gateway-deleted --nat-gateway-ids "$NAT_ID"

echo "==> Освобождаем Elastic IP"
for attempt in 1 2 3 4 5 6 7 8 9 10; do
  if aws ec2 release-address --allocation-id "$ALLOC_ID" 2> /dev/null; then
    rm -f "$STATE_FILE"
    echo "Готово. NAT Gateway и Elastic IP удалены"
    exit 0
  fi
  echo "Адрес ещё привязан, попытка $attempt из 10"
  sleep 15
done
echo "Не удалось освободить Elastic IP $ALLOC_ID" >&2
exit 1
