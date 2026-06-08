import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';

const defaultApiBaseUrl = 'http://staraz.site/api';

void main() {
  runApp(const SealyApp());
}

class SealyApp extends StatelessWidget {
  const SealyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Sealy',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF145DF5)),
        useMaterial3: true,
      ),
      home: const AppShell(),
    );
  }
}

class AppShell extends StatefulWidget {
  const AppShell({super.key});

  @override
  State<AppShell> createState() => _AppShellState();
}

class _AppShellState extends State<AppShell> {
  final _apiController = TextEditingController(text: defaultApiBaseUrl);
  final _loginController = TextEditingController();
  final _passwordController = TextEditingController();
  final _picker = ImagePicker();

  SharedPreferences? _prefs;
  String? _token;
  AppUser? _user;
  MobileBootstrap? _bootstrap;
  int _tabIndex = 0;
  bool _busy = false;
  String _phase = 'ingreso';
  String _message = 'Listo para iniciar.';
  List<PendingCheck> _queue = [];
  List<CheckRecord> _records = [];
  NearestStore? _lastNearestStore;

  @override
  void initState() {
    super.initState();
    _loadState();
  }

  @override
  void dispose() {
    _apiController.dispose();
    _loginController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _loadState() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString('token');
    final apiBase = prefs.getString('apiBaseUrl');
    final userJson = prefs.getString('user');
    final bootstrapJson = prefs.getString('bootstrap');
    final queueJson = prefs.getString('queue');

    setState(() {
      _prefs = prefs;
      _token = token;
      if (apiBase != null && apiBase.isNotEmpty) {
        _apiController.text = apiBase;
      }
      if (userJson != null) {
        _user = AppUser.fromJson(jsonDecode(userJson) as Map<String, dynamic>);
      }
      if (bootstrapJson != null) {
        _bootstrap = MobileBootstrap.fromJson(jsonDecode(bootstrapJson) as Map<String, dynamic>);
      }
      if (queueJson != null) {
        final list = jsonDecode(queueJson) as List<dynamic>;
        _queue = list.map((item) => PendingCheck.fromJson(item as Map<String, dynamic>)).toList();
      }
    });
  }

  String get _apiBaseUrl => _apiController.text.trim().replaceAll(RegExp(r'/+$'), '');

  bool get _isLoggedIn => _token != null && _user != null;

  Future<bool> _isOnline() async {
    try {
      final uri = Uri.parse(_apiBaseUrl);
      final host = uri.host.isEmpty ? 'google.com' : uri.host;
      final result = await InternetAddress.lookup(host).timeout(const Duration(seconds: 5));
      return result.isNotEmpty && result.first.rawAddress.isNotEmpty;
    } catch (_) {
      return false;
    }
  }

  Future<Map<String, dynamic>> _request(
    String path, {
    String method = 'GET',
    Map<String, dynamic>? body,
    bool allow422 = false,
  }) async {
    final uri = Uri.parse('$_apiBaseUrl$path');
    final headers = <String, String>{
      'Content-Type': 'application/json',
      if (_token != null) 'Authorization': 'Bearer $_token',
    };

    http.Response response;
    try {
      if (method == 'POST') {
        response = await http
            .post(uri, headers: headers, body: jsonEncode(body ?? {}))
            .timeout(const Duration(seconds: 30));
      } else {
        response = await http.get(uri, headers: headers).timeout(const Duration(seconds: 30));
      }
    } on SocketException {
      throw ApiException('Sin datos o sin internet. Conectate e intenta de nuevo.', 0);
    } on TimeoutException {
      throw ApiException('La conexion tardo demasiado. Revisa tus datos o internet.', 0);
    } on http.ClientException {
      throw ApiException('No se pudo conectar con el servidor. Revisa tus datos o internet.', 0);
    }

    Map<String, dynamic> decoded;
    try {
      final parsed = response.body.isEmpty ? <String, dynamic>{} : jsonDecode(response.body);
      if (parsed is! Map<String, dynamic>) {
        throw const FormatException('Respuesta no valida');
      }
      decoded = parsed;
    } on FormatException {
      throw ApiException('Sin datos o servidor no disponible. Conectate e intenta de nuevo.', response.statusCode);
    }

    final ok = response.statusCode >= 200 && response.statusCode < 300;
    if (!ok && !(allow422 && response.statusCode == 422)) {
      throw ApiException(
        decoded['message']?.toString() ?? decoded['error']?.toString() ?? 'No se pudo completar la solicitud ${response.statusCode}',
        response.statusCode,
      );
    }
    return decoded;
  }

  Future<void> _login() async {
    await _runBusy(() async {
      final data = await _request('/auth/login', method: 'POST', body: {
        'login': _loginController.text.trim(),
        'password': _passwordController.text,
        'client_type': 'mobile',
      });

      _token = data['token'] as String;
      _user = AppUser.fromJson(data['user'] as Map<String, dynamic>);
      await _prefs?.setString('apiBaseUrl', _apiBaseUrl);
      await _prefs?.setString('token', _token!);
      await _prefs?.setString('user', jsonEncode(_user!.toJson()));
      await _loadBootstrap();
      _message = 'Sesion iniciada.';
    });
  }

  Future<void> _logout() async {
    await _prefs?.remove('token');
    await _prefs?.remove('user');
    setState(() {
      _token = null;
      _user = null;
      _bootstrap = null;
      _records = [];
      _message = 'Sesion cerrada.';
    });
  }

  Future<void> _loadBootstrap() async {
    final data = await _request('/me/mobile-bootstrap');
    final bootstrap = MobileBootstrap.fromJson(data);
    setState(() {
      _bootstrap = bootstrap;
      _syncSelectedPhaseWithToday();
    });
    await _prefs?.setString('bootstrap', jsonEncode(bootstrap.toJson()));
  }

  Future<void> _makeCheck() async {
    await _runBusy(() async {
      final availablePhases = _availablePhaseOptions;
      if (availablePhases.isEmpty) {
        _message = 'Ya registraste las 4 checadas de hoy.';
        return;
      }
      if (!availablePhases.any((item) => item.key == _phase)) {
        _phase = availablePhases.first.key;
      }
      final position = await _currentPosition();
      final mockLocation = position.isMocked;
      if (mockLocation) {
        _message = 'Ubicacion simulada detectada. Desactiva apps de GPS falso.';
        return;
      }
      if (position.accuracy > 75) {
        _message = 'GPS impreciso (${position.accuracy.toStringAsFixed(1)} m). Intenta en area abierta.';
        return;
      }
      if (_deviceClockLooksSuspicious()) {
        _message = 'Hora del dispositivo sospechosa. Activa fecha y hora automaticas.';
        return;
      }
      final nearest = _nearestStore(position);
      if (nearest == null) {
        throw ApiException('No hay tiendas activas disponibles.', 400);
      }
      _lastNearestStore = nearest;
      final store = nearest.store;
      final clientDistance = nearest.distanceMeters;

      if (clientDistance > store.allowedRadiusMeters) {
        _message =
            'Bloqueado: tienda más cercana ${store.name} a ${clientDistance.toStringAsFixed(1)} m. Radio permitido ${store.allowedRadiusMeters} m.';
        setState(() {});
        return;
      }

      final image = await _picker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: CameraDevice.front,
        imageQuality: 55,
        maxWidth: 720,
      );
      if (image == null) {
        throw ApiException('La foto es obligatoria.', 400);
      }

      final photoBase64 = 'data:image/jpeg;base64,${base64Encode(await image.readAsBytes())}';
      final pending = PendingCheck(
        localId: DateTime.now().microsecondsSinceEpoch.toString(),
        storeId: store.id,
        phase: _phase,
        capturedAtDevice: DateTime.now().toIso8601String(),
        latitude: position.latitude,
        longitude: position.longitude,
        gpsAccuracyMeters: position.accuracy,
        gpsIsMocked: mockLocation,
        distanceMetersClient: clientDistance,
        deviceId: await _deviceId(),
        photoBase64: photoBase64,
      );

      if (!await _isOnline()) {
        await _addToQueue(pending);
        _message = 'Checada guardada offline. Pendiente de sincronizar.';
        return;
      }

      final data = await _request(
        '/checks',
        method: 'POST',
        body: pending.toApiJson(),
        allow422: true,
      );

      if (data['status'] == 'blocked_out_of_range') {
        _message =
            'Bloqueado: ${_readDouble(data['distance_meters']).toStringAsFixed(1)} m de la tienda. Radio ${data['allowed_radius_meters']} m.';
      } else {
        _message = 'Checada registrada. Distancia: ${_readDouble(data['distance_meters']).toStringAsFixed(1)} m.';
        await _loadBootstrap();
      }
    });
  }

  Future<void> _syncQueue() async {
    await _runBusy(() async {
      if (_queue.isEmpty) {
        _message = 'No hay pendientes por sincronizar.';
        return;
      }
      if (!await _isOnline()) {
        _message = 'Sin conexion. La cola sigue pendiente.';
        return;
      }

      final data = await _request('/checks/sync', method: 'POST', body: {
        'device_id': await _deviceId(),
        'items': _queue.map((item) => item.toSyncJson()).toList(),
      });

      final syncedIds = ((data['synced'] ?? []) as List<dynamic>)
          .map((item) => (item as Map<String, dynamic>)['local_id']?.toString())
          .whereType<String>()
          .toSet();

      _queue = _queue.where((item) => !syncedIds.contains(item.localId)).toList();
      await _saveQueue();
      await _loadBootstrap();

      final rejected = ((data['rejected'] ?? []) as List<dynamic>).length;
      _message = 'Sincronizados: ${syncedIds.length}. Rechazados: $rejected.';
    });
  }

  Future<void> _loadRecords() async {
    await _runBusy(() async {
      if (!await _isOnline()) {
        _message = 'Sin datos o sin internet. Conectate para actualizar el historial.';
        return;
      }
      final now = DateTime.now();
      final start = '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-01';
      final end =
          '${now.year.toString().padLeft(4, '0')}-${now.month.toString().padLeft(2, '0')}-${now.day.toString().padLeft(2, '0')}';
      final data = await _request('/checks/my?start=$start&end=$end');
      final items = (data['items'] ?? []) as List<dynamic>;
      _records = items.map((item) => CheckRecord.fromJson(item as Map<String, dynamic>)).toList();
      _message = 'Historial actualizado.';
    });
  }

  Future<void> _addToQueue(PendingCheck check) async {
    _queue = [..._queue, check];
    await _saveQueue();
    setState(() {});
  }

  Future<void> _saveQueue() async {
    await _prefs?.setString('queue', jsonEncode(_queue.map((item) => item.toJson()).toList()));
    setState(() {});
  }

  Future<String> _deviceId() async {
    var id = _prefs?.getString('deviceId');
    if (id == null || id.isEmpty) {
      id = DateTime.now().microsecondsSinceEpoch.toString();
      await _prefs?.setString('deviceId', id);
    }
    return id;
  }

  Future<Position> _currentPosition() async {
    final enabled = await Geolocator.isLocationServiceEnabled();
    if (!enabled) {
      throw ApiException('Activa el GPS para poder checar.', 400);
    }

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      throw ApiException('Permiso de ubicacion requerido.', 400);
    }

    return Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(
        accuracy: LocationAccuracy.high,
        timeLimit: Duration(seconds: 20),
      ),
    );
  }

  bool _deviceClockLooksSuspicious() {
    final serverTime = _bootstrap?.serverTime;
    if (serverTime == null) {
      return false;
    }
    final parsed = DateTime.tryParse(serverTime);
    if (parsed == null) {
      return false;
    }
    return DateTime.now().isBefore(parsed.subtract(const Duration(minutes: 5)));
  }

  Future<void> _runBusy(Future<void> Function() action) async {
    setState(() {
      _busy = true;
      _message = 'Procesando...';
    });
    try {
      await action();
    } catch (err) {
      _message = err.toString().replaceFirst('Exception: ', '');
    } finally {
      if (mounted) {
        setState(() => _busy = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_prefs == null) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }

    return Scaffold(
      appBar: AppBar(
        title: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Image.asset('assets/sealy.png', width: 34, height: 34, fit: BoxFit.contain),
            const SizedBox(width: 10),
            const Text('Sealy'),
          ],
        ),
        actions: [
          if (_isLoggedIn)
            IconButton(
              tooltip: 'Cerrar sesion',
              onPressed: _busy ? null : _logout,
              icon: const Icon(Icons.logout),
            ),
        ],
      ),
      body: _isLoggedIn ? _buildHome() : _buildLogin(),
      bottomNavigationBar: _isLoggedIn
          ? NavigationBar(
              selectedIndex: _tabIndex,
              onDestinationSelected: (index) {
                setState(() => _tabIndex = index);
                if (index == 1 && _records.isEmpty && !_busy) {
                  _loadRecords();
                }
              },
              destinations: const [
                NavigationDestination(icon: Icon(Icons.camera_alt_outlined), label: 'Checar'),
                NavigationDestination(icon: Icon(Icons.history), label: 'Registros'),
                NavigationDestination(icon: Icon(Icons.sync), label: 'Sync'),
              ],
            )
          : null,
    );
  }

  Widget _buildLogin() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _InfoCard(
          title: 'Ingreso personal',
          child: Column(
            children: [
              Image.asset('assets/sealy.png', height: 110, fit: BoxFit.contain),
              const SizedBox(height: 16),
              TextField(
                controller: _loginController,
                decoration: const InputDecoration(labelText: 'Correo, telefono o numero empleado'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _passwordController,
                decoration: const InputDecoration(labelText: 'Contrasena'),
                obscureText: true,
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _busy ? null : _login,
                icon: const Icon(Icons.login),
                label: const Text('Ingresar'),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),
        Text(_message),
      ],
    );
  }

  Widget _buildHome() {
    final pages = [_buildCheckTab(), _buildRecordsTab(), _buildSyncTab()];
    return Stack(
      children: [
        pages[_tabIndex],
        if (_busy)
          Container(
            color: Colors.black.withValues(alpha: 0.08),
            child: const Center(child: CircularProgressIndicator()),
          ),
      ],
    );
  }

  Widget _buildCheckTab() {
    final availablePhases = _availablePhaseOptions;
    return RefreshIndicator(
      onRefresh: _loadBootstrap,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          _InfoCard(
            title: _user?.fullName ?? 'Personal',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(_storeSummaryText()),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  children: [
                    const Chip(
                      label: Text('Operacion normal'),
                      avatar: Icon(Icons.wifi, size: 18),
                    ),
                    Chip(
                      label: Text('${_queue.length} pendiente(s)'),
                      avatar: const Icon(Icons.sync_problem, size: 18),
                    ),
                  ],
                ),
              ],
            ),
          ),
          _InfoCard(
            title: 'Nueva checada',
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                if (availablePhases.isEmpty)
                  const Text('Ya registraste las 4 checadas de hoy.')
                else
                  DropdownButtonFormField<String>(
                    initialValue: availablePhases.any((item) => item.key == _phase) ? _phase : availablePhases.first.key,
                    decoration: const InputDecoration(labelText: 'Fase'),
                    items: availablePhases
                        .map((item) => DropdownMenuItem(value: item.key, child: Text(item.value)))
                        .toList(),
                    onChanged: (value) => setState(() => _phase = value ?? availablePhases.first.key),
                  ),
                const SizedBox(height: 12),
                FilledButton.icon(
                  onPressed: _busy || availablePhases.isEmpty ? null : _makeCheck,
                  icon: const Icon(Icons.camera_alt),
                  label: const Text('Tomar foto y checar'),
                ),
                const SizedBox(height: 8),
                Text(_message),
              ],
            ),
          ),
          _InfoCard(
            title: 'Checadas de hoy',
            child: _buildTodayChecks(),
          ),
        ],
      ),
    );
  }

  List<MapEntry<String, String>> get _phaseOptions => const [
        MapEntry('ingreso', 'Ingreso'),
        MapEntry('salida_comer', 'Salida a comer'),
        MapEntry('entrada_comer', 'Entrada de comer'),
        MapEntry('salida', 'Salida'),
      ];

  Set<String> get _checkedPhasesToday => (_bootstrap?.todayChecks ?? []).map((check) => check.phase).toSet();

  List<MapEntry<String, String>> get _availablePhaseOptions {
    final checked = _checkedPhasesToday;
    return _phaseOptions.where((item) => !checked.contains(item.key)).toList();
  }

  void _syncSelectedPhaseWithToday() {
    final available = _availablePhaseOptions;
    if (available.isNotEmpty && _checkedPhasesToday.contains(_phase)) {
      _phase = available.first.key;
    }
  }

  Widget _buildTodayChecks() {
    final checks = _bootstrap?.todayChecks ?? [];
    if (checks.isEmpty) {
      return const Text('Sin checadas registradas hoy.');
    }
    return Column(
      children: checks
          .map(
            (check) => ListTile(
              dense: true,
              contentPadding: EdgeInsets.zero,
              leading: const Icon(Icons.check_circle_outline),
              title: Text(_phaseLabel(check.phase)),
              subtitle: Text('${check.checkedAt} - ${_statusLabel(check.status)}'),
            ),
          )
          .toList(),
    );
  }

  Widget _buildRecordsTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        FilledButton.icon(
          onPressed: _busy ? null : _loadRecords,
          icon: const Icon(Icons.refresh),
          label: const Text('Actualizar historial'),
        ),
        const SizedBox(height: 12),
        Text(_message),
        const SizedBox(height: 12),
        if (_records.isEmpty)
          const _InfoCard(title: 'Historial', child: Text('Sin registros cargados.'))
        else
          ..._records.map(
            (record) => _InfoCard(
              title: '${record.checkDate} - ${_phaseLabel(record.phase)}',
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '${record.storeLabel} - ${record.distanceMeters.toStringAsFixed(1)} m - ${_statusLabel(record.status)}\n'
                    'Laborado: ${record.workedTime} / Comida: ${record.mealTime}',
                  ),
                  if (record.photoUrl.isNotEmpty) ...[
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: _token == null ? null : () => _showRecordPhoto(record),
                      icon: const Icon(Icons.photo),
                      label: const Text('Ver foto'),
                    ),
                  ],
                ],
              ),
            ),
          ),
      ],
    );
  }

  Widget _buildSyncTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        _InfoCard(
          title: 'Cola pendiente',
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('${_queue.length} checada(s) pendientes.'),
              const SizedBox(height: 8),
              const Text('Usa esta seccion solo cuando necesites reenviar checadas pendientes.'),
              const SizedBox(height: 12),
              FilledButton.icon(
                onPressed: _busy ? null : _syncQueue,
                icon: const Icon(Icons.sync),
                label: const Text('Sincronizar ahora'),
              ),
              const SizedBox(height: 8),
              Text(_message),
            ],
          ),
        ),
        ..._queue.map(
          (item) => _InfoCard(
            title: _phaseLabel(item.phase),
            child: Text('${item.capturedAtDevice}\nDistancia cliente: ${item.distanceMetersClient.toStringAsFixed(1)} m'),
          ),
        ),
      ],
    );
  }

  NearestStore? _nearestStore(Position position) {
    final stores = _bootstrap?.activeStores ?? [];
    if (stores.isEmpty) {
      return null;
    }
    NearestStore? nearest;
    for (final store in stores) {
      final distance = distanceMeters(
        position.latitude,
        position.longitude,
        store.latitude,
        store.longitude,
      );
      final candidate = NearestStore(store: store, distanceMeters: distance);
      if (nearest == null || candidate.distanceMeters < nearest.distanceMeters) {
        nearest = candidate;
      }
    }
    return nearest;
  }

  void _showRecordPhoto(CheckRecord record) {
    showDialog<void>(
      context: context,
      builder: (context) => Dialog(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Align(
                alignment: Alignment.centerRight,
                child: IconButton(
                  tooltip: 'Cerrar',
                  onPressed: () => Navigator.of(context).pop(),
                  icon: const Icon(Icons.close),
                ),
              ),
              Image.network(
                '$_apiBaseUrl${record.photoUrl}',
                headers: {'Authorization': 'Bearer $_token'},
                fit: BoxFit.contain,
                loadingBuilder: (context, child, progress) {
                  if (progress == null) return child;
                  return const Padding(
                    padding: EdgeInsets.all(32),
                    child: CircularProgressIndicator(),
                  );
                },
                errorBuilder: (_, __, ___) => const Padding(
                  padding: EdgeInsets.all(24),
                  child: Text('No se pudo cargar la foto.'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String _storeSummaryText() {
    final count = _bootstrap?.activeStores.length ?? 0;
    if (count == 0) {
      return 'Sin tiendas activas cargadas. Actualiza con internet.';
    }
    final nearest = _lastNearestStore;
    if (nearest != null) {
      final inside = nearest.distanceMeters <= nearest.store.allowedRadiusMeters;
      final storeName = nearest.store.chain.isEmpty ? nearest.store.name : '${nearest.store.chain} - ${nearest.store.name}';
      return '$storeName es la más cercana: ${nearest.distanceMeters.toStringAsFixed(1)} m. ${inside ? "Dentro del radio" : "Fuera del radio"}.';
    }
    return '$count tienda(s) activas cargadas. Al checar se usará la tienda más cercana dentro de 50 m.';
  }
}

class NearestStore {
  NearestStore({required this.store, required this.distanceMeters});

  final StoreInfo store;
  final double distanceMeters;
}

class _InfoCard extends StatelessWidget {
  const _InfoCard({required this.title, required this.child});

  final String title;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 10),
            child,
          ],
        ),
      ),
    );
  }
}

class ApiException implements Exception {
  ApiException(this.message, this.statusCode);

  final String message;
  final int statusCode;

  @override
  String toString() => message;
}

class AppUser {
  AppUser({required this.id, required this.fullName, required this.role});

  final int id;
  final String fullName;
  final String role;

  factory AppUser.fromJson(Map<String, dynamic> json) {
    return AppUser(
      id: _readInt(json['id']),
      fullName: json['full_name']?.toString() ?? '',
      role: json['role']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'full_name': fullName,
        'role': role,
      };
}

class StoreInfo {
  StoreInfo({
    required this.id,
    required this.chain,
    required this.name,
    required this.latitude,
    required this.longitude,
    required this.allowedRadiusMeters,
  });

  final int id;
  final String chain;
  final String name;
  final double latitude;
  final double longitude;
  final int allowedRadiusMeters;

  factory StoreInfo.fromJson(Map<String, dynamic> json) {
    return StoreInfo(
      id: _readInt(json['id']),
      chain: json['chain']?.toString() ?? '',
      name: json['name']?.toString() ?? '',
      latitude: _readDouble(json['latitude']),
      longitude: _readDouble(json['longitude']),
      allowedRadiusMeters: _readInt(json['allowed_radius_meters'], fallback: 50),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'chain': chain,
        'name': name,
        'latitude': latitude,
        'longitude': longitude,
        'allowed_radius_meters': allowedRadiusMeters,
      };
}

class MobileBootstrap {
  MobileBootstrap({required this.activeStores, required this.todayChecks, this.serverTime});

  final List<StoreInfo> activeStores;
  final List<TodayCheck> todayChecks;
  final String? serverTime;

  factory MobileBootstrap.fromJson(Map<String, dynamic> json) {
    final stores = (json['active_stores'] ?? []) as List<dynamic>;
    final checks = (json['today_checks'] ?? []) as List<dynamic>;
    return MobileBootstrap(
      activeStores: stores.map((item) => StoreInfo.fromJson(item as Map<String, dynamic>)).toList(),
      todayChecks: checks.map((item) => TodayCheck.fromJson(item as Map<String, dynamic>)).toList(),
      serverTime: json['server_time']?.toString(),
    );
  }

  Map<String, dynamic> toJson() => {
        'active_stores': activeStores.map((item) => item.toJson()).toList(),
        'today_checks': todayChecks.map((item) => item.toJson()).toList(),
        'server_time': serverTime,
      };
}

class TodayCheck {
  TodayCheck({required this.phase, required this.checkedAt, required this.status});

  final String phase;
  final String checkedAt;
  final String status;

  factory TodayCheck.fromJson(Map<String, dynamic> json) {
    return TodayCheck(
      phase: json['phase']?.toString() ?? '',
      checkedAt: json['checked_at']?.toString() ?? '',
      status: json['status']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toJson() => {
        'phase': phase,
        'checked_at': checkedAt,
        'status': status,
      };
}

class PendingCheck {
  PendingCheck({
    required this.localId,
    required this.storeId,
    required this.phase,
    required this.capturedAtDevice,
    required this.latitude,
    required this.longitude,
    required this.gpsAccuracyMeters,
    required this.gpsIsMocked,
    required this.distanceMetersClient,
    required this.deviceId,
    required this.photoBase64,
  });

  final String localId;
  final int storeId;
  final String phase;
  final String capturedAtDevice;
  final double latitude;
  final double longitude;
  final double gpsAccuracyMeters;
  final bool gpsIsMocked;
  final double distanceMetersClient;
  final String deviceId;
  final String photoBase64;

  factory PendingCheck.fromJson(Map<String, dynamic> json) {
    return PendingCheck(
      localId: json['local_id']?.toString() ?? '',
      storeId: _readInt(json['store_id']),
      phase: json['phase']?.toString() ?? '',
      capturedAtDevice: json['captured_at_device']?.toString() ?? '',
      latitude: _readDouble(json['latitude']),
      longitude: _readDouble(json['longitude']),
      gpsAccuracyMeters: _readDouble(json['gps_accuracy_meters']),
      gpsIsMocked: json['gps_is_mocked'] == true,
      distanceMetersClient: _readDouble(json['distance_meters_client']),
      deviceId: json['device_id']?.toString() ?? '',
      photoBase64: json['photo_base64']?.toString() ?? '',
    );
  }

  Map<String, dynamic> toJson() => {
        'local_id': localId,
        'store_id': storeId,
        'phase': phase,
        'captured_at_device': capturedAtDevice,
        'latitude': latitude,
        'longitude': longitude,
        'gps_accuracy_meters': gpsAccuracyMeters,
        'gps_is_mocked': gpsIsMocked,
        'distance_meters_client': distanceMetersClient,
        'device_id': deviceId,
        'photo_base64': photoBase64,
      };

  Map<String, dynamic> toApiJson() => {
        'store_id': storeId,
        'phase': phase,
        'captured_at_device': capturedAtDevice,
        'latitude': latitude,
        'longitude': longitude,
        'gps_accuracy_meters': gpsAccuracyMeters,
        'gps_is_mocked': gpsIsMocked,
        'device_time_iso': DateTime.now().toIso8601String(),
        'device_id': deviceId,
        'photo_base64': photoBase64,
      };

  Map<String, dynamic> toSyncJson() => {
        'local_id': localId,
        'store_id': storeId,
        'phase': phase,
        'captured_at_device': capturedAtDevice,
        'latitude': latitude,
        'longitude': longitude,
        'gps_accuracy_meters': gpsAccuracyMeters,
        'gps_is_mocked': gpsIsMocked,
        'device_time_iso': DateTime.now().toIso8601String(),
        'distance_meters_client': distanceMetersClient,
        'photo_base64': photoBase64,
      };
}

class CheckRecord {
  CheckRecord({
    required this.checkDate,
    required this.phase,
    required this.storeChain,
    required this.storeName,
    required this.distanceMeters,
    required this.status,
    required this.workedTime,
    required this.mealTime,
    required this.photoUrl,
  });

  final String checkDate;
  final String phase;
  final String storeChain;
  final String storeName;
  final double distanceMeters;
  final String status;
  final String workedTime;
  final String mealTime;
  final String photoUrl;

  String get storeLabel => storeChain.isEmpty ? storeName : '$storeChain - $storeName';

  factory CheckRecord.fromJson(Map<String, dynamic> json) {
    return CheckRecord(
      checkDate: json['check_date']?.toString() ?? '',
      phase: json['phase']?.toString() ?? '',
      storeChain: json['store_chain']?.toString() ?? '',
      storeName: json['store_name']?.toString() ?? '',
      distanceMeters: _readDouble(json['distance_meters']),
      status: json['status']?.toString() ?? '',
      workedTime: json['tiempo_laborado']?.toString() ?? '',
      mealTime: json['tiempo_comida']?.toString() ?? '',
      photoUrl: json['photo_url']?.toString() ?? '',
    );
  }
}

double distanceMeters(double lat1, double lng1, double lat2, double lng2) {
  const earth = 6371000.0;
  final dLat = _degToRad(lat2 - lat1);
  final dLng = _degToRad(lng2 - lng1);
  final a = pow(sin(dLat / 2), 2) +
      cos(_degToRad(lat1)) * cos(_degToRad(lat2)) * pow(sin(dLng / 2), 2);
  return earth * 2 * atan2(sqrt(a), sqrt(1 - a));
}

double _degToRad(double degrees) => degrees * pi / 180;

double _readDouble(dynamic value, {double fallback = 0}) {
  if (value is num) {
    return value.toDouble();
  }
  return double.tryParse(value?.toString() ?? '') ?? fallback;
}

int _readInt(dynamic value, {int fallback = 0}) {
  if (value is num) {
    return value.toInt();
  }
  return int.tryParse(value?.toString() ?? '') ?? fallback;
}

String _phaseLabel(String phase) {
  switch (phase) {
    case 'ingreso':
      return 'Ingreso';
    case 'salida_comer':
      return 'Salida a comer';
    case 'entrada_comer':
      return 'Entrada de comer';
    case 'salida':
      return 'Salida';
    default:
      return phase;
  }
}

String _statusLabel(String status) {
  switch (status) {
    case 'valid':
      return 'Valida';
    case 'synced':
      return 'Sincronizada';
    case 'manual_review':
      return 'Revision manual';
    case 'blocked_out_of_range':
      return 'Fuera de rango';
    case 'rejected':
      return 'Rechazada';
    default:
      return status;
  }
}

