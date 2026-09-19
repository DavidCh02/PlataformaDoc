' Lanzador silencioso del programador de Laravel.
' Lo usa la tarea "PlataformaDoc schedule" para ejecutarse cada minuto
' SIN mostrar la ventana negra (cmd) a cada rato.
CreateObject("Wscript.Shell").Run "C:\xampp\htdocs\PlataformaDoc\infra\schedule-run.bat", 0, False
