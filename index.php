<?php
// --- 1. CONFIGURACIÓN SEGURA ---
$databaseUrl = getenv('DATABASE_URL');

if (!$databaseUrl) {
    die("Error crítico: No se encontró la variable de entorno DATABASE_URL");
}

// Desarmamos la URL
$db = parse_url($databaseUrl);

// CORRECCIÓN: Si el puerto no viene en la URL, usamos el 5432 por defecto
$port = isset($db['port']) ? $db['port'] : '5432';

// Construimos el DSN con el puerto seguro
$dsn = "pgsql:" . sprintf(
    "host=%s;port=%s;user=%s;password=%s;dbname=%s;sslmode=require",
    $db['host'],
    $port, // Usamos la variable validada
    $db['user'],
    $db['pass'],
    ltrim($db['path'], "/")
);

$data = [];
$paises_para_filtro = []; 
$filtro_pais = isset($_GET['pais']) ? $_GET['pais'] : ''; 
$error = null;

try {
    // Conexión
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- 2. OBTENER LISTA DE PAÍSES ---
    $stmtPaises = $pdo->query("SELECT DISTINCT pais FROM dim_pais ORDER BY pais");
    $paises_para_filtro = $stmtPaises->fetchAll(PDO::FETCH_COLUMN);

    // --- 3. CONSTRUIR LA CONSULTA OLAP ---
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

    $params = [];
    if ($filtro_pais && $filtro_pais !== 'TODOS') {
        $sql .= " WHERE p.pais = ? ";
        $params[] = $filtro_pais;
    }

    $sql .= " 
        GROUP BY CUBE(p.pais, prod.categoria)
        ORDER BY p.pais NULLS LAST, prod.categoria NULLS LAST;
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error de conexión: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard OLAP Seguro</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f4f9; padding: 20px; color: #333; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        h1 { margin-top: 0; color: #2c3e50; text-align: center;}
        .filter-box { background-color: #eef2f5; padding: 15px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: center; gap: 10px; }
        select, button { padding: 10px; border-radius: 5px; border: 1px solid #ccc; font-size: 1rem; }
        button { background-color: #00D2A0; color: white; border: none; cursor: pointer; font-weight: bold; }
        button:hover { background-color: #00b88d; }
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
    <h1>📊 Reporte de Ventas</h1>
    
    <div class="filter-box">
        <form method="GET" action="">
            <label for="pais">Filtrar por País: </label>
            <select name="pais" id="pais">
                <option value="TODOS">-- Ver Todo --</option>
                <?php foreach ($paises_para_filtro as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $p === $filtro_pais ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Aplicar Filtro</button>
        </form>
    </div>

    <?php if ($error): ?>
        <div style="background: #ffdddd; color: #a00; padding: 15px; border-radius: 5px; text-align: center;">
            <strong>Error:</strong> <?= $error ?>
        </div>
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
                    <tr><td colspan="4" style="text-align:center">No hay datos.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</body>
</html>
