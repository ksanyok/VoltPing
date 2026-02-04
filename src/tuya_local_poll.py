#!/usr/bin/env python3
"""
Tuya Local Poll - отримання статусу пристрою через локальний протокол
Використовує tinytuya для надійного підключення

Запуск: python3 tuya_local_poll.py
Повертає JSON з даними пристрою

Встановлення: pip3 install tinytuya
"""

import sys
import json
import os
import signal

# Timeout handler
def timeout_handler(signum, frame):
    print(json.dumps({
        "online": False, 
        "error": "Timeout",
        "dps": {}
    }))
    sys.exit(1)

# Set alarm for hard timeout
HARD_TIMEOUT = max(2, int(os.environ.get('TUYA_TIMEOUT', '5')) - 1)
signal.signal(signal.SIGALRM, timeout_handler)
signal.alarm(HARD_TIMEOUT)

try:
    import tinytuya
except ImportError:
    print(json.dumps({
        "error": "tinytuya not installed. Run: pip3 install tinytuya", 
        "online": False,
        "dps": {}
    }))
    sys.exit(1)

# Configuration from environment variables
DEVICE_ID = os.environ.get('TUYA_DEVICE_ID', '')
LOCAL_KEY = os.environ.get('TUYA_LOCAL_KEY', '')
HOST = os.environ.get('TUYA_HOST', '')
VERSION = float(os.environ.get('TUYA_VERSION', '3.5'))
TIMEOUT = int(os.environ.get('TUYA_TIMEOUT', '5'))

def main():
    if not DEVICE_ID or not LOCAL_KEY or not HOST:
        print(json.dumps({
            "error": "Missing configuration: TUYA_DEVICE_ID, TUYA_LOCAL_KEY, TUYA_HOST required",
            "online": False,
            "dps": {}
        }))
        sys.exit(1)
    
    try:
        # Create device connection
        device = tinytuya.OutletDevice(
            dev_id=DEVICE_ID,
            address=HOST,
            local_key=LOCAL_KEY,
            version=VERSION
        )
        device.set_socketTimeout(TIMEOUT)
        device.set_socketRetryLimit(1)
        
        # Get status
        data = device.status()
        
        if data is None or 'Error' in data:
            error_msg = data.get('Error', 'Unknown error') if data else 'No response'
            print(json.dumps({
                "online": False,
                "error": error_msg,
                "dps": {}
            }))
            sys.exit(1)
        
        dps = data.get('dps', {})
        
        # Extract values from DPS
        # DPS 20 = voltage (often * 10)
        voltage_raw = dps.get('20', dps.get(20, 0))
        voltage = round(float(voltage_raw) / 10, 1) if voltage_raw and float(voltage_raw) > 1000 else float(voltage_raw or 0)
        
        # DPS 19 = power (often * 10)
        power_raw = dps.get('19', dps.get(19, 0))
        power = round(float(power_raw) / 10, 1) if power_raw else 0
        
        # DPS 18 = current (often * 1000)
        current_raw = dps.get('18', dps.get(18, 0))
        current = round(float(current_raw) / 1000, 3) if current_raw else 0
        
        # DPS 1 = switch status
        switch_on = dps.get('1', dps.get(1, False))
        
        result = {
            "online": True,
            "voltage": voltage,
            "power": power,
            "current": current,
            "switch": switch_on,
            "dps": dps,
            "error": None
        }
        
        print(json.dumps(result))
        
    except Exception as e:
        print(json.dumps({
            "online": False,
            "error": str(e),
            "dps": {}
        }))
        sys.exit(1)

if __name__ == '__main__':
    main()
