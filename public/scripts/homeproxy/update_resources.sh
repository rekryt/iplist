#!/bin/sh
SERVICE_URL="https://iplist.opencck.org"

NAME="homeproxy"

RESOURCES_DIR="/etc/$NAME/resources"
mkdir -p "$RESOURCES_DIR"

RUN_DIR="/var/run/$NAME"
LOG_PATH="$RUN_DIR/$NAME.log"
mkdir -p "$RUN_DIR"

log() {
    echo -e "$(date "+%Y-%m-%d %H:%M:%S") $*" >> "$LOG_PATH"
}

set_lock() {
    local act="$1"
    local type="$2"

    local lock="$RUN_DIR/update_resources-$type.lock"
    if [ "$act" = "set" ]; then
        if [ -e "$lock" ]; then
            log "[$(to_upper "$type")] A task is already running."
            exit 2
        else
            touch "$lock"
        fi
    elif [ "$act" = "remove" ]; then
        rm -f "$lock"
    fi
}

to_upper() {
    echo -e "$1" | tr "[a-z]" "[A-Z]"
}

check_list_update() {
    local listtype="$1"
    local listrepo="$2"
    local listref="$3"
    local listname="$4"
    local wget="wget --timeout=10 -q"

    set_lock "set" "$listtype"

    $wget "$listrepo/?format=text&data=$listref" -O "$RUN_DIR/$listname"
    if [ ! -s "$RUN_DIR/$listname" ]; then
        rm -f "$RUN_DIR/$listname"
        log "[$listrepo/?format=text&data=$listref] Update failed."

        set_lock "remove" "$listtype"
        return 1
    fi

    mv -f "$RUN_DIR/$listname" "$RESOURCES_DIR/$listtype.${listname##*.}"
    echo -e "$(date +%F\ %H:%M:%S)" > "$RESOURCES_DIR/$listtype.ver"
    log "[$listrepo/?format=text&data=$listref] Successfully updated."

    set_lock "remove" "$listtype"
    return 0
}

case "$1" in
"china_ip4")
    check_list_update "$1" "$SERVICE_URL" "cidr4" "ipv4.txt"
    ;;
"china_ip6")
    check_list_update "$1" "$SERVICE_URL" "cidr6" "ipv6.txt"
    ;;
"gfw_list")
    check_list_update "$1" "$SERVICE_URL" "domains" "gfw.txt"
    ;;
"china_list")
    check_list_update "$1" "$SERVICE_URL" "domains" "direct-list.txt"
    ;;
*)
    echo -e "Usage: $0 <china_ip4 / china_ip6 / gfw_list / china_list>"
    exit 1
    ;;
esac