echo -n '' | tee /var/log/nginx/access.log
echo -n '' | tee /var/log/nginx/error.log

cd /home/isucon/isucon6q/
./isucon6q-bench

cat /var/log/nginx/access.log | awk '{res_time_sum[$3" "$4] += $7;counts[$3" "$4]++}END{for (i in res_time_sum) {print i"\t"res_time_sum[i]}"\t"counts[i]}' | sort -n -k 3
