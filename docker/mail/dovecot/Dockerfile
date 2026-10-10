FROM alpine:3.21

RUN apk add --no-cache dovecot dovecot-lmtpd openssl bash \
 && adduser -u 5000 -D -H -s /sbin/nologin vmail \
 && mkdir -p /var/mail/vhosts /run/dovecot \
 && chown vmail:vmail /var/mail/vhosts

COPY dovecot.conf /etc/dovecot/dovecot.conf
COPY entrypoint.sh /entrypoint.sh
RUN chmod 755 /entrypoint.sh && chmod 644 /etc/dovecot/dovecot.conf

EXPOSE 143 993

ENTRYPOINT ["/entrypoint.sh"]
