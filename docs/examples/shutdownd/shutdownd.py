#!/usr/bin/env python3

import os
import signal
import socket
import subprocess
import sys

SOCKET = "/run/shutdownd.sock"
POWEROFF = ["/usr/bin/systemctl", "poweroff"]

def cleanup(*args):
    if os.path.exists(SOCKET):
        os.remove(SOCKET)
    sys.exit(0)

signal.signal(signal.SIGTERM, cleanup)
signal.signal(signal.SIGINT, cleanup)

if os.path.exists(SOCKET):
    os.remove(SOCKET)

server = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
server.bind(SOCKET)

# root:www-data
os.chown(SOCKET, 0, 33)
os.chmod(SOCKET, 0o660)

server.listen()

while True:
    conn, _ = server.accept()

    with conn:
        command = conn.recv(64).decode().strip()

        if command == "shutdown":
            conn.sendall(b"OK\n")
            subprocess.Popen(POWEROFF)
        else:
            conn.sendall(b"DENIED\n")
