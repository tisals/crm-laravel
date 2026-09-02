#!/bin/bash
# Compare row counts - use PREPARE statements per table
mysql -uroot -psailus_root_dev -B -N <<'EOF' 2>&1
SELECT 'TNAME', 'minerva', 'legacy', 'diff';
EOF

# Get list of tables in minerva
tables=$(mysql -uroot -psailus_root_dev -B -N -e "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA='minerva' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;")

echo "Tables to compare: $(echo "$tables" | wc -l)"
printf "%-35s %10s %10s %10s\n" "TABLE" "minerva" "legacy" "diff"
echo "--------------------------------------------------------------------------------"

for tbl in $tables; do
  # Get count from minerva
  m_cnt=$(mysql -uroot -psailus_root_dev -B -N -e "SELECT COUNT(*) FROM minerva.\`$tbl\`" 2>/dev/null)
  if [ -z "$m_cnt" ]; then m_cnt=0; fi
  # Get count from tecnoinnsoft_crm (skip if table doesn't exist there)
  l_cnt=$(mysql -uroot -psailus_root_dev -B -N -e "SELECT COUNT(*) FROM tecnoinnsoft_crm.\`$tbl\`" 2>/dev/null)
  if [ -z "$l_cnt" ]; then l_cnt=0; fi
  # Compute diff
  diff=$((l_cnt - m_cnt))
  # Only show if legacy has data OR if diff is significant
  if [ "$l_cnt" -gt 0 ] || [ "$m_cnt" -gt 0 ]; then
    printf "%-35s %10s %10s %10s\n" "$tbl" "$m_cnt" "$l_cnt" "$diff"
  fi
done