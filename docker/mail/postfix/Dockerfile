FROM alpine:3.21

RUN apk add --no-cache postfix opendkim opendkim-utils openssl gettext bash \
 && mkdir -p /etc/opendkim/keys /var/spool/postfix/pid \
 && chmod 755 /var/spool/postfix/pid

COPY master.cf /etc/postfix/master.cf
COPY main.cf.tpl /etc/postfix/main.cf.tpl
COPY entrypoint.sh /entrypoint.sh
# 644 for config: files extracted from a zip can arrive world-writable, and
# Postfix warns (and Dovecot may refuse) about config others can edit.
RUN chmod 755 /entrypoint.sh && chmod 644 /etc/postfix/master.cf /etc/postfix/main.cf.tpl

EXPOSE 25 587

ENTRYPOINT ["/entrypoint.sh"]
