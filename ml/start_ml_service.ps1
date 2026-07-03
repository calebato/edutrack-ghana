$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$python = Join-Path $root '.venv\Scripts\python.exe'
if (-not (Test-Path $python)) {
    throw 'Advanced ML environment missing. Install ml/requirements-advanced.txt first.'
}
& $python (Join-Path $root 'ml_service.py')
