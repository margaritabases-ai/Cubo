<?php
// --- 1. CONFIGURACIÓN Y CONEXIÓN ---
$databaseUrl = getenv('DATABASE_URL');
if (!$databaseUrl) die("Error: Falta DATABASE_URL");

$db = parse_url($databaseUrl);
$dsn = "pgsql:host=" . $db["host"] . ";port=" . $db["port"] . ";dbname=" . ltrim($db["path"], "/");
$user = $db["user"];
$pass = $db["pass"];

$data = [];
$paises_para_filtro = []; // Lista para el dropdown
$filtro_pais = isset($_GET['pais']) ? $_GET['pais'] : ''; // Capturamos selección del usuario
$error = null;

try {
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // --- 2. OBTENER LISTA DE PAÍSES (Para el Dropdown) ---
    // Esto hace que tu filtro sea dinámico. Si agregas "Japón" a la BD, aparecerá solo aquí.
    $stmtPaises = $pdo->query("SELECT DISTINCT pais FROM dim_pais ORDER BY pais");
    $paises_para_filtro = $stmtPaises->fetchAll(PDO::FETCH_COLUMN);

    // --- 3. CONSTRUIR LA CONSULTA OLAP CON FILTRO ---
    $sql = "
        SELECT 
            COALESCE(p.pais, 'TOTAL GLOBAL') as pais,
            COALESCE(prod.categoria, 'TODAS') as categoria,
            SUM(v.total_dinero) as venta_total,
            SUM(v.cantidad) as unidades
        FROM fact_ventas v
        JOIN dim_pais p ON v.pais_id = p.id
        JOIN dim_producto prod ON v.producto_id = prod.id
    ";

    // Lógica del Filtro: Si el usuario eligió algo que no sea vacío
    $params = [];
    if ($filtro_pais && $filtro_pais !== 'TODOS') {
        $sql .= " WHERE p.pais = ? "; // Placeholder de seguridad
        $params[] = $filtro_pais;
    }

    // Cerramos el query con el GROUP BY CUBE
    $sql .= " 
        GROUP BY CUBE(p.pais, prod.categoria)
        ORDER BY p.pais NULLS LAST, prod.categoria NULLS LAST;
    ";

    // Ejecutamos con parámetros (Prevención SQL Injection)
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard OLAP</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f4f9; padding: 20px; color: #333; }
        
        .container {
            max-width: 900px; margin: 0 auto; background: white;
            padding: 30px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }

        h1 { margin-top: 0; color: #2c3e50; }

        /* Estilos del Formulario de Filtro */
        .filter-box {
            background-color: #eef2f5; padding: 15px; border-radius: 8px;
            margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
        }
        select, button {
            padding: 10px; border: 1px solid #ccc; border-radius: 5px; font-size: 1rem;
        }
        button {
            background-color: #00D2A0; color: white; border: none; cursor: pointer; font-weight: bold;
        }
        button:hover { background-color: #00b88d; }

        /* Estilos de Tabla (Igual que antes) */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; }
        th { background-color: #34495e; color: white; }
        
        .fila-subtotal { background-color: #e8f5e9; color: #2e7d32; font-weight: bold; }
        .fila-total-global { background-color: #2c3e50; color: #fff; font-weight: bold; font-size: 1.1em;}
        .fila-normal { color: #555; }
    </style>
</head>
<body>

<div class="container">
    <h1>📊 Explorador de Cubo OLAP</h1>
    
    <div class="filter-box">
        <form method="GET" action="">
            <label for="pais"><strong>Filtrar por País:</strong></label>
            <select name="pais" id="pais">
                <option value="TODOS">Ver Todo el Mundo</option>
                <?php foreach ($paises_para_filtro as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $p === $filtro_pais ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Actualizar Reporte</button>
        </form>
    </div>

    <?php if ($error): ?>
        <p style="color: red;"><?= $error ?></p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>País</th>
                    <th>Categoría</th>
                    <th>Venta Total ($)</th>
                    <th>Unidades</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($data) > 0): ?>
                    <?php foreach ($data as $fila): ?>
                        <?php 
                            $claseCss = "fila-normal";
                            if ($fila['pais'] === 'TOTAL GLOBAL') $claseCss = "fila-total-global";
                            elseif ($fila['categoria'] === 'TODAS') $claseCss = "fila-subtotal";
                        ?>
                        <tr class="<?= $claseCss ?>">
                            <td><?= htmlspecialchars($fila['pais']) ?></td>
                            <td><?= htmlspecialchars($fila['categoria']) ?></td>
                            <td>$<?= number_format($fila['venta_total'], 2) ?></td>
                            <td><?= $fila['unidades'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align:center">No hay datos para este filtro.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
