@echo off
REM Ejecuta el programador de Laravel (recordatorios de Telegram, limpieza, etc.).
REM Lo llama el Programador de tareas de Windows cada minuto.
cd /d C:\xampp\htdocs\PlataformaDoc
C:\xampp\php\php.exe artisan schedule:run >> storage\logs\schedule-run.log 2>&1
