**btop**

btop is created by aristocratos (https://github.com/aristocratos/btop) - this
plugin is only an Unraid integration of it.

Installs the btop resource monitor CLI and adds a dashboard tile showing
load average, CPU temperature and the top 5 processes by CPU usage.
Run `btop` from any terminal (SSH or web terminal).

Notes:
- Do not install alongside the NerdTools btop package (both ship /usr/bin/btop).
- GPU monitoring is unavailable (static binary cannot load NVML/ROCm).
- Your btop CLI settings persist across reboots (stored on the flash drive).